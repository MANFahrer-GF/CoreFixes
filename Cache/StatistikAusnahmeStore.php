<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Cache;

use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Log;

/**
 * Cache-Speicher, der Piloten-Statistik NIE behaelt — alles andere schon.
 *
 * # Der Anlass (22.10.2026)
 *
 * Mit eingeschaltetem Anwendungs-Cache erschienen Profil- und Bestenlisten-Werte
 * nach einer Landung erst Stunden spaeter: DisposableBasic cacht seine
 * Statistik bis Mitternacht UTC und loescht sie nirgends
 * (`DB_StatServices.php` Z. 38-51, 202-219). Vorgabe Thomas: mit Cache muss es
 * fuer Piloten **genauso sein wie heute** (`CACHE_DRIVER=null`).
 *
 * Deshalb kein "nach der Landung vergessen" — ein uebersehener Schluessel
 * fraere sonst still ein. Stattdessen werden Schluessel mit den Praefixen
 * unten gar nicht erst gespeichert. Fuer sie verhaelt sich dieser Speicher
 * exakt wie Laravels `NullStore`: lesen liefert null, schreiben liefert false.
 * Jeder Aufruf rechnet also frisch, wie heute.
 *
 * Geprueft wird der ROHschluessel, so wie der Modulcode ihn uebergibt:
 * `Repository::itemKey()` reicht ihn unveraendert durch, ein `CACHE_PREFIX`
 * haengt erst der innere Speicher an.
 *
 * # Wenn der innere Speicher ausfaellt
 *
 * Heute (NullStore) kann ein Cache-Zugriff nie scheitern. Damit ein Redis, der
 * mitten im Betrieb wegbricht, keine Seite kostet, faengt jede Methode Fehler
 * ab, antwortet wie NullStore und schaltet fuer den Rest des Prozesses auf
 * "wie heute" — einmal geloggt, nicht je Aufruf.
 */
final class StatistikAusnahmeStore implements Store, LockProvider
{
    /**
     * Schluessel-Anfaenge, die sich nach einer Landung aendern koennen — und Wetter.
     * Quelle: grep ueber `app/` und `modules/` am 22.10.2026, Bericht
     * `_backlog/2026-10-22-cache-driver-untersuchung.md` (Abschnitte B und C).
     * Ein NEUES Modul mit Piloten-Statistik muss hier eingetragen werden.
     */
    public const PRAEFIXE = [
        // DisposableBasic — Profil-Kacheln, Bestenlisten, Airline-Konto, Netzwerk-Anteile
        'pstats-',
        'lboard-',
        'journal-',
        'ns-',
        // SPPassport — Passport je Pilot, Bestenliste, Wochen-Top, seltene Laender
        'sppassport_dashboard_v',
        'sppassport_global_leaderboard',
        'sppassport_weekly_top',
        'sppassport_rare_countries',
        // SkyOps — Pilotenstatistik, Dashboard, Airline-Uebersicht, Filter aus PIREPs
        'skyops.pilot_stats',
        'skyops.dashboard',
        'skyops.airline_overview',
        'skyops.filter_options',
        // FleetDesk — Kostenbericht
        'fleetdesk.cost_report.',

        // WETTER — Vorgabe Thomas 22.10.2026: "Wetter immer frisch", also wie heute.
        // Core METAR/TAF (`app/Contracts/Metar.php`, config `airports.weather.`)
        'airports.weather.',
        // WeatherBriefing METAR/TAF — NICHT pauschal `wb_`: darunter liegt
        // `wb_view_version`, der das view:clear je Request beendet.
        'wb_metar_',
        'wb_taf_',
        // ATCNotepad METAR/TAF
        'atcnotepad_metar_',
        'atcnotepad_taf_',
        // LiveMap Wetter-Kacheln (OpenWeatherMap) samt Stoerungs-Merkern
        'livemap:owm:',
        // SPTheme und Kabinenansagen cachen ihr Wetter ausdruecklich im
        // Datei-Speicher (`Cache::store('file')`) — das tun sie heute schon,
        // diese Liste beruehrt sie nicht.
    ];

    /** Innerer Speicher ist in diesem Prozess ausgefallen → ab jetzt wie NullStore. */
    private bool $ausgefallen = false;

    public function __construct(private readonly Store&LockProvider $innen)
    {
    }

    public static function ausgenommen(string $key): bool
    {
        foreach (self::PRAEFIXE as $praefix) {
            if (str_starts_with($key, $praefix)) {
                return true;
            }
        }

        return false;
    }

    /** Ruft den inneren Speicher; scheitert er, die Antwort, die NullStore gaebe. */
    private function sicher(callable $aufruf, mixed $wieNullStore): mixed
    {
        if ($this->ausgefallen) {
            return $wieNullStore;
        }
        try {
            return $aufruf();
        } catch (\Throwable $e) {
            $this->ausgefallen = true;
            Log::warning('[CoreFixes] Cache-Speicher ausgefallen, dieser Prozess laeuft wie ohne Cache weiter: '.$e->getMessage());

            return $wieNullStore;
        }
    }

    public function get($key)
    {
        return self::ausgenommen((string) $key) ? null : $this->sicher(fn () => $this->innen->get($key), null);
    }

    public function many(array $keys)
    {
        $behalten = array_values(array_filter($keys, fn ($k) => ! self::ausgenommen((string) $k)));
        $werte = $behalten === [] ? [] : $this->sicher(fn () => $this->innen->many($behalten), []);

        $ergebnis = [];
        foreach ($keys as $key) {
            $ergebnis[$key] = $werte[$key] ?? null;
        }

        return $ergebnis;
    }

    public function put($key, $value, $seconds)
    {
        return self::ausgenommen((string) $key) ? false : $this->sicher(fn () => $this->innen->put($key, $value, $seconds), false);
    }

    public function putMany(array $values, $seconds)
    {
        $behalten = array_filter($values, fn ($k) => ! self::ausgenommen((string) $k), ARRAY_FILTER_USE_KEY);
        if ($behalten === []) {
            // NullStore::putMany liefert false — gleiches Ergebnis wie heute.
            return false;
        }
        $ok = $this->sicher(fn () => $this->innen->putMany($behalten, $seconds), false);

        return count($behalten) === count($values) ? $ok : false;
    }

    /**
     * Ausdruecklich vorhanden: Fehlt `add()`, nimmt `Repository::add()` einen
     * nicht-atomaren Ersatzweg (get + put), und Sperren verlieren ihre Wirkung.
     */
    public function add($key, $value, $seconds)
    {
        if (self::ausgenommen((string) $key)) {
            return false;
        }

        return $this->sicher(function () use ($key, $value, $seconds) {
            if (method_exists($this->innen, 'add')) {
                return $this->innen->add($key, $value, $seconds);
            }

            return $this->innen->get($key) === null ? $this->innen->put($key, $value, $seconds) : false;
        }, false);
    }

    public function increment($key, $value = 1)
    {
        return self::ausgenommen((string) $key) ? false : $this->sicher(fn () => $this->innen->increment($key, $value), false);
    }

    public function decrement($key, $value = 1)
    {
        return self::ausgenommen((string) $key) ? false : $this->sicher(fn () => $this->innen->decrement($key, $value), false);
    }

    public function forever($key, $value)
    {
        return self::ausgenommen((string) $key) ? false : $this->sicher(fn () => $this->innen->forever($key, $value), false);
    }

    /** Immer weiterreichen: raeumt auch Altbestand ausgenommener Schluessel ab. */
    public function forget($key)
    {
        return $this->sicher(fn () => $this->innen->forget($key), true);
    }

    public function flush()
    {
        return $this->sicher(fn () => $this->innen->flush(), true);
    }

    public function getPrefix()
    {
        return $this->innen->getPrefix();
    }

    /** Faellt der Speicher aus, eine Sperre wie heute: `NoLock`, immer frei (NullStore.php:79). */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return $this->sicher(fn () => $this->innen->lock($name, $seconds, $owner), new NoLock($name, $seconds, $owner));
    }

    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Tags an den inneren Speicher (Redis kann sie, der Datei-Speicher wirft
     * BadMethodCallException — das faengt der einzige Nutzer, Gate-AutoRepair,
     * selbst ab). Getaggte Schluessel umgehen die Ausnahmeliste; keiner der
     * Praefixe oben wird getaggt.
     */
    public function tags($names)
    {
        if (! method_exists($this->innen, 'tags')) {
            throw new \BadMethodCallException('Dieser Cache-Speicher unterstuetzt keine Tags.');
        }

        return $this->innen->tags($names);
    }

    /** Was Laravel sonst am Speicher aufruft (`connection()`, `lockConnection()` ...). */
    public function __call(string $methode, array $argumente)
    {
        return $this->innen->{$methode}(...$argumente);
    }
}
