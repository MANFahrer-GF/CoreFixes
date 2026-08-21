<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Providers;

use App\Http\Controllers\Api\AcarsController;
use App\Models\Flight;
use App\Models\Pirep;
use Illuminate\Support\ServiceProvider;
use Modules\CoreFixes\Http\Controllers\AcarsControllerFix;
use Modules\CoreFixes\Observers\FlightNumberObserver;
use Modules\CoreFixes\Observers\PirepBlockTimeObserver;
use Modules\CoreFixes\Services\DisposableCronFix;
use Modules\CoreFixes\Widgets\ActiveBookingsFix;
use Modules\DisposableBasic\Widgets\ActiveBookings;
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
 */
final class CoreFixesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Pirep::observe(PirepBlockTimeObserver::class);
        Flight::observe(FlightNumberObserver::class);
    }

    public function register(): void
    {
        // Alle vier Aufrufe in DisposableSpecials Gen_Cron holen den Dienst ueber
        // app(DS_CronServices::class) — diese eine Bindung deckt sie alle ab.
        $this->app->bind(DS_CronServices::class, DisposableCronFix::class);

        // Arrilot baut jedes Widget ueber den Container
        // (AbstractWidgetFactory::instantiateWidget, `$this->app->make($widgetClass)`),
        // deshalb greift eine Bindung auch hier. Die Pruefung eine Zeile davor
        // laeuft gegen den KLASSENNAMEN, nicht gegen die Instanz — unsere
        // Ableitung erbt von der Vorlage und besteht sie damit.
        $this->app->bind(ActiveBookings::class, ActiveBookingsFix::class);

        // Laravels ControllerDispatcher loest Controller ueber den Container
        // auf (`Route::controller` -> `$container->make($class)`), deshalb
        // greift die Bindung fuer alle ACARS-Routen, ohne dass eine Route
        // umgeschrieben werden muss. Die Ableitung erbt saemtliche uebrigen
        // Endpunkte (acars_get, acars_logs, acars_events, route_*) und
        // aendert nur `acars_store`.
        $this->app->bind(AcarsController::class, AcarsControllerFix::class);
    }
}
