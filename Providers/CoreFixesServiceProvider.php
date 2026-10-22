<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Providers;

use App\Http\Controllers\Api\AcarsController;
use App\Models\Flight;
use App\Models\User;
use App\Models\Pirep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use App\Http\Controllers\Frontend\ProfileController;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Modules\CoreFixes\Cache\StatistikCacheManager;
use Modules\CoreFixes\Http\Controllers\AcarsControllerFix;
use Modules\CoreFixes\Http\Controllers\ProfileControllerFix;
use Modules\CoreFixes\Http\Middleware\CartoSchluessel;
use Modules\CoreFixes\Observers\FlightNumberObserver;
use Modules\CoreFixes\Observers\PirepBlockTimeObserver;
use Modules\CoreFixes\Observers\UserCallsignObserver;
use Modules\CoreFixes\Services\DisposableCronFix;
use Modules\CoreFixes\Widgets\ActiveBookingsFix;
use Modules\CoreFixes\Widgets\LeaderBoardFix;
use Modules\DisposableBasic\Widgets\ActiveBookings;
use Modules\DisposableBasic\Widgets\LeaderBoard;
use Modules\DisposableSpecial\Services\DS_CronServices;

/**
 * CoreFixes — haelt Korrekturen an phpVMS-Core-Bugs update-fest.
 *
 * Grundsatz: KEINE Core-Datei anfassen. Jedes phpVMS-Update ueberschreibt
 * `app/`-Dateien, und ein handgepatchter Core-Bug ist danach still wieder da
 * (genau das ist auf GSG schon zweimal passiert). Stattdessen haengt sich dieses
 * Modul von aussen in die Model-Events und korrigiert das VERHALTEN.
 *
 * Registrierte Fixes:
 *   1. PirepBlockTimeObserver — block_off_time/block_on_time bleiben NULL, weil
 *      der Core-Fallback in PirepService::create() am CarbonCast scheitert
 *      (NULL liest sich als "jetzt" und ist damit truthy).
 *   2. FlightNumberObserver — blockiert (wirft eine Exception) Flight-Saves
 *      mit `flight_number <= 0` (typisch: DisposableSpecial-Freiflug-
 *      Formular). Keine stille `return false` — siehe der Klasse eigener
 *      Doc-Kommentar, warum das hier eine falsche Erfolgsmeldung + eine
 *      Bid gegen einen nie gespeicherten Flight erzeugen wuerde.
 *   3. DisposableCronFix — DisposableSpecials Wochen-Cron sprengte an zwei
 *      Stellen das MySQL-Platzhalterlimit (Fehler 1390) und war dadurch
 *      14 Wochen still tot. Gleiches Prinzip wie oben, nur eine Ebene hoeher:
 *      statt die Moduldatei zu patchen (weg beim naechsten Modul-Update),
 *      wird die Service-Klasse im Container ausgetauscht.
 *   4. ActiveBookingsFix — DisposableBasics Buchungs-Widget riss die Seite mit
 *   5. LeaderBoardFix — Bestenliste beschriftete einen Durchschnitt wie einen Bestwert
 *   6. Zeitplan-Sperren — withoutOverlapping() war ohne Cache wirkungslos
 *      HTTP 500 mit, wenn eine Buchung kein OFP-XML hat oder auf einer weich
 *      geloeschten Maschine sitzt (beide Wege reproduziert). Wieder Container-
 *      Tausch statt Dateipatch; die Ansicht selbst bleibt unangetastet und
 *      bekommt nur noch darstellbare Zeilen.
 *   5. AcarsControllerFix — ein wiederholt gesendeter Positions-Batch legte
 *      DOPPELTE Zeilen an (gemessen 19-122 je Flug). Der Core hat fuer
 *      diesen Fall bereits einen idempotenten Zweig, benutzt darin aber den
 *      Query-Builder — der setzt keine Timestamps, wodurch `created_at`
 *      NULL bliebe und die Track-Sortierung kippen wuerde. Der Fix nimmt
 *      an dieser einen Stelle Eloquent. Wieder Container-Tausch statt
 *      Dateipatch.
 *   7. ProfileControllerFix — `/profile/{id}`, `/users/{id}` und
 *      `/pilots/{id}` zeigen alle auf `ProfileController@show(int $id)`.
 *      Ruft jemand einen dieser Pfade mit Text auf, wirft PHP einen
 *      TypeError, und der Besucher bekommt HTTP 500 statt 404. Wieder
 *      Container-Tausch; eine Routen-Einschraenkung waere hier wirkungslos
 *      (Begruendung im Doc-Kommentar der Fix-Klasse).
 *   8. Anwendungs-Cache mit Statistik-Ausnahme — schaltet per
 *      `COREFIXES_APP_CACHE` den Cache ein, ohne dass Profil- und
 *      Bestenlisten-Werte nach der Landung einfrieren (siehe
 *      `anwendungsCacheEinschalten()` und `StatistikAusnahmeStore`).
 */
final class CoreFixesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Pirep::observe(PirepBlockTimeObserver::class);
        Flight::observe(FlightNumberObserver::class);
        User::observe(UserCallsignObserver::class);
        $this->zeitplanSperrenReparieren();
        $this->cartoSchluesselEinhaengen();
        $this->sperrdauerKuerzen();
    }

    /**
     * Dem ZEITPLAN einen eigenen Speicher für seine Sperren geben — ohne die
     * Anwendung cachen zu lassen.
     *
     * ⚠️ Der Anlass, 22.08.2026: Der tägliche Health-Report kam zweimal, obwohl er
     * ein `withoutOverlapping()` trägt. Dieser Schutz arbeitet über den Cache, und
     * auf GSG steht `CACHE_DRIVER=null`. Gemessen: der Mutex ließ sich zweimal
     * hintereinander belegen — er hat NIE blockiert. Betroffen war jeder
     * Überlappungsschutz im ganzen phpVMS, nicht nur dieser eine Bericht.
     *
     * ⚠️ `CACHE_DRIVER` bleibt bewusst auf `null` (Vorgabe Thomas): mit
     * Anwendungs-Cache erscheinen PIREPs und Bewertungen verzögert. `useCache()`
     * betrifft AUSSCHLIESSLICH die Sperrdateien des Zeitplans — die Anwendung cacht
     * dadurch keine einzige Abfrage, keine Ansicht, keine Route.
     *
     * Die Sperren landen unter `storage/framework/cache/data` und räumen sich
     * selbst ab (Laravel setzt eine Ablaufzeit je Aufgabe).
     */
    /**
     * Den CARTO-Schluessel an die Kacheln der Karten haengen.
     *
     * Siehe `CartoSchluessel` fuer den Grund. Registriert wird in der
     * Gruppe `web` — die Anmelde- und API-Wege brauchen es nicht, und je
     * weniger Wege eine HTML-Umschrift beruehrt, desto besser.
     *
     * Ohne hinterlegten Schluessel tut die Zwischenschicht nichts; sie
     * prueft das als Erstes und reicht die Antwort unveraendert weiter.
     */
    private function cartoSchluesselEinhaengen(): void
    {
        $this->app->booted(function (): void {
            try {
                /** @var \Illuminate\Routing\Router $router */
                $router = $this->app->make(\Illuminate\Routing\Router::class);
                $router->pushMiddlewareToGroup('web', CartoSchluessel::class);
            } catch (\Throwable $e) {
                // Ein fehlender Kartenschluessel darf die Seite nicht kosten.
                Log::warning('[CoreFixes] CARTO-Zwischenschicht nicht registriert: '.$e->getMessage());
            }
        });
    }

    /** Wie lange eine Sperre haechstens gelten soll, in Minuten. */
    private const SPERRE_MINUTEN = 10;

    /** Bis zu welchem Takt (in Minuten) eine Aufgabe als "haeufig" gilt. */
    private const HAEUFIG_BIS_MINUTEN = 5;

    /**
     * Die Vorgabe von `withoutOverlapping()` auf ein vertretbares Mass bringen.
     *
     * # Der Anlass (26.08.2026, gemeldet aus FleetDesk)
     *
     * `withoutOverlapping()` gilt in Laravel **1440 Minuten — vierundzwanzig
     * Stunden** (`Event::$expiresAt`, Zeile 94). Solange der Mutex gar nicht
     * funktionierte, war das folgenlos. Seit `zeitplanSperrenReparieren()`
     * entsteht die Sperre wirklich — und damit auch ihre Kehrseite:
     *
     * Freigegeben wird sie in einem `finally` (Event.php:308). Ein normaler
     * Fehler raeumt sie also ab. Ein hartes Ende nicht: Neustart, OOM-Kill,
     * PHP-Fatal. Dann liegt die Sperrdatei **einen ganzen Tag** und blockiert
     * jeden weiteren Start. Lautlos, ohne eine Zeile im Log.
     *
     * Bei einem taeglichen Bericht faellt das kaum auf. Bei einer
     * Minuten-Aufgabe ist es ein Ausfall: In FleetDesk lagen sieben
     * Gespraechsauftraege von drei Piloten liegen, ein Pilot meldete "die KI
     * hat sich aufgehaengt".
     *
     * # Was hier passiert — und was NICHT
     *
     * Gekuerzt wird ausschliesslich die **unveraenderte Vorgabe** (1440), und
     * nur bei Aufgaben, die mindestens alle fuenf Minuten laufen. Wer eine
     * Dauer ausdruecklich gesetzt hat, hat eine Entscheidung getroffen — die
     * wird nicht ueberschrieben.
     *
     * Und es geschieht nicht stillschweigend: Was gekuerzt wurde, steht im
     * Log. Eine Zeitplan-Aenderung hinter dem Ruecken des Modulautors waere
     * genau die Art von Stille, gegen die dieses Modul gebaut ist.
     *
     * # Warum zehn Minuten und nicht zwei
     *
     * Die Sperre muss laenger gelten als ein Lauf dauert, sonst startet der
     * naechste mitten hinein — also genau das, was sie verhindern soll.
     * `--max-time=55` begrenzt einen Warteschlangen-Arbeiter NICHT auf 55
     * Sekunden: `Worker::stopIfNecessary()` prueft ZWISCHEN den Jobs
     * (Worker.php:302). Ein einzelner Job, der auf eine fremde API wartet,
     * laeuft zu Ende — und der Lauf mit ihm.
     *
     * Zehn Minuten sind grosszuegig genug fuer fast jede Minuten-Aufgabe und
     * heilen eine liegengebliebene Sperre in einer Viertelstunde statt in
     * einem Tag. Wer es enger braucht, setzt es selbst.
     */
    private function sperrdauerKuerzen(): void
    {
        $this->app->booted(function (): void {
            if (! $this->app->bound(Schedule::class)) {
                return;
            }
            try {
                $schedule = $this->app->make(Schedule::class);
                $gekuerzt = [];

                foreach ($schedule->events() as $event) {
                    if (! ($event->withoutOverlapping ?? false)) {
                        continue;
                    }
                    // Nur die unangetastete Vorgabe. Eine ausdrueckliche
                    // Wahl des Autors bleibt stehen.
                    if (($event->expiresAt ?? null) !== 1440) {
                        continue;
                    }
                    if (! self::laeuftHaeufig((string) ($event->expression ?? ''))) {
                        continue;
                    }
                    $event->expiresAt = self::SPERRE_MINUTEN;
                    $gekuerzt[] = trim((string) ($event->description ?: $event->command ?: $event->expression));
                }

                if ($gekuerzt !== []) {
                    Log::info(
                        '[CoreFixes] Sperrdauer auf '.self::SPERRE_MINUTEN.' Minuten gekuerzt '
                        .'(Laravel-Vorgabe waeren 1440): '.implode(' | ', $gekuerzt)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[CoreFixes] Sperrdauer nicht angepasst: '.$e->getMessage());
            }
        });
    }

    /**
     * Laeuft diese Aufgabe mindestens alle `HAEUFIG_BIS_MINUTEN` Minuten?
     *
     * Ausgewertet wird nur das Minutenfeld des Cron-Ausdrucks — mehr ist
     * fuer die Frage nicht noetig, und ein halber Cron-Parser waere eine
     * eigene Fehlerquelle.
     *
     *   "* * * * *"    → jede Minute        → ja
     *   "*\/5 * * * *"  → alle fuenf Minuten → ja
     *   "*\/15 * * * *" → alle 15 Minuten    → nein
     *   "0 3 * * *"    → einmal taeglich    → nein
     */
    private static function laeuftHaeufig(string $ausdruck): bool
    {
        $felder = preg_split('/\s+/', trim($ausdruck)) ?: [];
        $minute = $felder[0] ?? '';

        if ($minute === '*') {
            return true;
        }
        if (preg_match('#^\*/(\d+)$#', $minute, $m) === 1) {
            return (int) $m[1] <= self::HAEUFIG_BIS_MINUTEN;
        }

        return false;
    }

    private function zeitplanSperrenReparieren(): void
    {
        // Nur eingreifen, wenn der Anwendungs-Cache tatsächlich nichts behält —
        // sonst nähme man einer Installation mit funktionierendem Cache ihre
        // eigene Wahl (etwa Redis, wo Sperren serverübergreifend gelten).
        if (config('cache.default') !== null && config('cache.default') !== '' && config('cache.default') !== 'null') {
            return;
        }

        $this->app->booted(function (): void {
            if (! $this->app->bound(Schedule::class)) {
                return;
            }
            try {
                $this->app->make(Schedule::class)->useCache('file');
            } catch (\Throwable $e) {
                // Ein fehlender file-Store darf den Start nicht verhindern; dann
                // bleibt es beim alten Zustand (ungeschützt, aber lauffähig).
                Log::warning('CoreFixes: Zeitplan-Sperren konnten nicht auf den Datei-Speicher gelegt werden', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * FIX 8 — Anwendungs-Cache einschalten, ohne dass Piloten-Statistik einfriert.
     *
     * Schalter: `.env` `COREFIXES_APP_CACHE=redis` (oder `file`, oder ein anderer
     * Store aus `config/cache.php`). Fehlt der Eintrag, passiert hier NICHTS —
     * die Installation laeuft wie mit `CACHE_DRIVER=null`.
     *
     * # Warum hier und nicht ueber `CACHE_DRIVER`
     *
     * `CACHE_DRIVER` bleibt `null`. Faellt dieses Modul aus (deaktiviert, Fehler,
     * Update), ist damit automatisch wieder der Zustand von heute da — statt
     * still eingefrorener Statistik. Gemessen 22.10.2026 an einer Kopie von Live
     * (`/api/status`, OPcache): null 653 ms, Cache ueber `CACHE_DRIVER` 361 ms,
     * dieser Weg 387 ms. Den Rest holen Modulliste und Aktivstatus, die nwidart
     * schon vor diesem Provider liest; sie bleiben bewusst ungecacht.
     *
     * # Was genau umgestellt wird — und was bewusst NICHT
     *
     * - Der Cache-Manager wird gegen `StatistikCacheManager` getauscht. Dessen
     *   Standard-Store ist der gewaehlte Store, eingepackt in
     *   `StatistikAusnahmeStore` (Statistik-Schluessel werden nie gespeichert,
     *   ein ausgefallener Redis antwortet wie heute).
     * - Die CONFIG bleibt unberuehrt (`cache.default` bleibt `null`): phpVMS ruft
     *   `config:cache` selbst auf, ein dort eingefrorener Laufzeitwert legte ohne
     *   dieses Modul jede Seite lahm. Mit gecachter Config liest `env()` nichts —
     *   der Schalter ist dann AUS, also wieder wie heute.
     * - Drosseln (`throttle:`) bleiben wirkungslos wie heute: der RateLimiter
     *   bekommt ausdruecklich den Null-Speicher, z. B. `simbrief/check_ofp` 10/min.
     * - Bereits gebaute Instanzen werden verworfen: `cache`, `cache.store`,
     *   `cache.psr6`, RateLimiter und der Facade-Zwischenspeicher.
     * - Zeitplan-Sperren bleiben wie heute im Datei-Speicher
     *   (`zeitplanSperrenReparieren()` liest die unveraenderte Config).
     * - Modulliste und Aktivstatus, die nwidart schon vorher gelesen hat, bleiben
     *   ungecacht (nwidart haelt den alten Manager).
     *
     * Nach dem Einschalten wirken Settings und Modul-Aktivstatus gecacht: wer sie
     * direkt in der DB aendert, braucht danach `php artisan cache:clear`.
     */
    private function anwendungsCacheEinschalten(): void
    {
        $ziel = trim((string) env('COREFIXES_APP_CACHE', ''));
        if ($ziel === '') {
            return;
        }
        $standard = config('cache.default');
        if ($standard !== null && $standard !== '' && $standard !== 'null') {
            // Wer CACHE_DRIVER selbst gesetzt hat, hat entschieden — dann nicht
            // darueberlegen, sondern laut melden: dort fehlt die Ausnahmeliste.
            Log::warning('[CoreFixes] COREFIXES_APP_CACHE ignoriert: CACHE_DRIVER ist schon "'.$standard.'" — Statistik-Ausnahmen greifen NICHT.');

            return;
        }

        try {
            if ($ziel === 'null' || $ziel === StatistikCacheManager::STORE || ! is_array(config('cache.stores.'.$ziel))) {
                throw new \InvalidArgumentException('kein Cache-Store "'.$ziel.'" in config/cache.php');
            }

            // Laedt den aufgeschobenen CacheServiceProvider, falls noch nicht
            // geschehen — sonst ueberschriebe er spaeter unsere Bindung.
            $this->app->make('cache');

            $manager = new StatistikCacheManager($this->app, $ziel);
            // Probe VOR dem Tausch: ein nicht erreichbarer Redis soll den Start
            // nicht kosten, sondern beim alten Zustand bleiben. Kurze Zeitgrenze,
            // damit ein haengender Redis keine Minute blockiert.
            if (config('cache.stores.'.$ziel.'.driver') === 'redis') {
                $verbindung = 'database.redis.'.(config('cache.stores.'.$ziel.'.connection') ?? 'default');
                config([
                    $verbindung.'.timeout' => config($verbindung.'.timeout') ?: 1.0,
                    $verbindung.'.read_timeout' => config($verbindung.'.read_timeout') ?: 1.0,
                ]);
            }
            $innen = $manager->store($ziel)->getStore();
            $innen->get('corefixes:probe');
            if (! $innen instanceof LockProvider) {
                throw new \InvalidArgumentException('Store "'.$ziel.'" kann keine Sperren');
            }

            $this->app->instance('cache', $manager);
            $this->app->singleton(RateLimiter::class, fn ($app) => new RateLimiter($app->make('cache')->driver('null')));
            foreach (['cache.store', 'cache.psr6', RateLimiter::class] as $instanz) {
                $this->app->forgetInstance($instanz);
            }
            Cache::clearResolvedInstance('cache');
        } catch (\Throwable $e) {
            Log::warning('[CoreFixes] Anwendungs-Cache NICHT eingeschaltet, bleibt wie bisher: '.$e->getMessage());
        }
    }

    public function register(): void
    {
        $this->anwendungsCacheEinschalten();

        // Alle vier Aufrufe in DisposableSpecials Gen_Cron holen den Dienst ueber
        // app(DS_CronServices::class) — diese eine Bindung deckt sie alle ab.
        $this->app->bind(DS_CronServices::class, DisposableCronFix::class);

        // Arrilot baut jedes Widget ueber den Container
        // (AbstractWidgetFactory::instantiateWidget, `$this->app->make($widgetClass)`),
        // deshalb greift eine Bindung auch hier. Die Pruefung eine Zeile davor
        // laeuft gegen den KLASSENNAMEN, nicht gegen die Instanz — unsere
        // Ableitung erbt von der Vorlage und besteht sie damit.
        $this->app->bind(ActiveBookings::class, ActiveBookingsFix::class);

        // Bestenliste: Durchschnitts-Typen als solche beschriften. Die
        // Theme-Fassung der Ansicht wirft die richtige Überschrift weg —
        // siehe LeaderBoardFix.
        $this->app->bind(LeaderBoard::class, LeaderBoardFix::class);

        // Laravels ControllerDispatcher loest Controller ueber den Container
        // auf (`Route::controller` -> `$container->make($class)`), deshalb
        // greift die Bindung fuer alle ACARS-Routen, ohne dass eine Route
        // umgeschrieben werden muss. Die Ableitung erbt saemtliche uebrigen
        // Endpunkte (acars_get, acars_logs, acars_events, route_*) und
        // aendert nur `acars_store`.
        $this->app->bind(AcarsController::class, AcarsControllerFix::class);

        // FIX 7 — `profile/{id}`, `users/{id}`, `pilots/{id}` beantworten Text
        // mit 404 statt 500. Warum ueber den Container und nicht ueber eine
        // Routen-Einschraenkung: siehe den Doc-Kommentar von ProfileControllerFix
        // (Provider-boot laeuft vor der Routen-Registrierung, und diese
        // Installation faehrt route:cache).
        $this->app->bind(ProfileController::class, ProfileControllerFix::class);
    }
}
