<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Observers;

use App\Models\Flight;
use Illuminate\Support\Facades\Log;
use Modules\CoreFixes\Exceptions\FlightNumberInvalidException;

/**
 * CORE-FIX 2 — Flight-Number-Hard-Block (Freiflug/DisposableSpecial).
 *
 * Hintergrund (Pilot-Befund Ralf T., GSG0016, 2026-07-28): das DisposableSpecial-
 * Freiflug-Formular akzeptiert eine "0" im Flight-Number-Feld als gueltige
 * Eingabe (nur ein LEERES Feld wird abgelehnt) und speichert sie unveraendert.
 * `flights.flight_number` ist `int(10) unsigned NOT NULL` — eine echte NULL-
 * Korrektur ist hier NICHT moeglich (anders als bei den PIREP-Block-Zeiten
 * oben): ein Observer, der auf `null` normalisiert, wuerde den Save entweder
 * hart brechen (NOT NULL) oder (schlimmer) klaglos wieder auf 0 zurueckfallen
 * (dieser Server faehrt `sql_mode` OHNE STRICT_TRANS_TABLES). Ausserdem darf
 * `flight_number` NICHT stillschweigend auf `null`/leer umgeschrieben werden,
 * weil das ueber die phpVMS-API als `flight_number: null` bei JEDEM Consumer
 * ankaeme — der AeroACARS-Client deserialisiert das Feld als nicht-optionalen
 * String und wuerde bei `null` die KOMPLETTE Bids/Flights-Antwort nicht mehr
 * parsen koennen.
 *
 * v1.2.0 (2026-07-28, Thomas-Entscheid): STATT NUR ZU LOGGEN blockiert dieser
 * Observer den Save jetzt hart (wirft eine Exception), wenn `flight_number <= 0`
 * ist. Grund fuer die Kehrtwende: `DS_FreeFlightController::store()`
 * (`modules/DisposableSpecial/Http/Controllers/DS_FreeFlightController.php`,
 * verifiziert auf Live) prueft den Rueckgabewert von `$freeflight->save()`
 * NIRGENDS — der Code legt danach UNBEDINGT einen `Bid` gegen
 * `$freeflight->id` an und zeigt eine Erfolgsmeldung. Ein Observer, der den
 * Save nur per `return false` abbricht (ohne Exception), wuerde das NICHT
 * verhindern: die Flight-Zeile existiert nie in der DB, aber Bid + "Personal
 * Flight Updated & Bid Inserted"-Erfolgsmeldung + SimBrief-Redirect passieren
 * trotzdem — ein stiller, viel schlimmerer Bug (kaputte Buchung sieht wie
 * Erfolg aus) als das urspruengliche Anzeige-Problem. Eine geworfene Exception
 * dagegen bricht die GESAMTE Anfrage ab, BEVOR die Bid-Zeile angelegt wird.
 *
 * UX (v1.2.1 nachgebessert): DisposableSpecial duerfen wir laut Vorgabe nicht
 * anfassen, koennen also keine Flash-Meldung im selben Stil wie die anderen
 * Validierungen in diesem Formular zeigen ("Check Flight Number!"). Aber die
 * geworfene `FlightNumberInvalidException` (siehe deren eigener Doc-
 * Kommentar) definiert ihre eigene `render()` — ein echter, DE/EN-locale-
 * aware Fehlerbildschirm statt Laravels generischer Standardfehlerseite.
 * APP_DEBUG=false auf Live verifiziert (keine Stacktrace/Info-Leaks, auch
 * vorher schon).
 *
 * Nebenwirkung (bewusst in Kauf genommen, live verifiziert 2026-07-28): 9
 * bereits bestehende Flight-Zeilen haben aktuell `flight_number <= 0`
 * (ueberwiegend inaktive/unsichtbare Freiflug-Entwuerfe anderer Piloten).
 * Jeder KUENFTIGE Save-Versuch auf einer dieser Zeilen (z.B. der Pilot
 * bearbeitet seinen alten Entwurf erneut) wird jetzt ebenfalls hart
 * blockiert, bis die Flugnummer korrigiert wird — das ist der gewuenschte
 * Effekt, keine Regression: genau diese Zeilen sind ja der Symptom-Fall.
 *
 * Die zusaetzliche Client-Reparatur (Callsign wird VOR flight_number
 * bevorzugt angezeigt, `resolveFlightIdent()` in `src/lib/callsign.ts`,
 * exakt wie phpVMS' eigener `Flight::atc()`-Accessor) bleibt bestehen — sie
 * greift fuer alle Piloten SOFORT beim naechsten Client-Update, waehrend
 * dieser Block nur NEUE Faelle verhindert und alte erst beim naechsten
 * Bearbeiten erzwingt.
 *
 * v1.10.2 (2026-09-01) — EIN ALTLASTFALL DARF KEINEN CRON-LAUF MITNEHMEN
 *
 * Im Log stand seit Monaten jede Nacht um 01:02 dieselbe Zeile. Der Kern-
 * Listener `SetActiveFlights` (app/Cron/Nightly/SetActiveFlights.php) laeuft
 * per `cursor()` durch ALLE aktiven Fluege und ruft auf jedem `save()`, um
 * `visible` zu setzen. Trifft er dabei die eine Altlastzeile mit
 * `flight_number = 0`, wirft dieser Observer — und die Exception verlaesst
 * die `each()`-Schleife. Gemessen am 01.09.2026: der Flug stand an Position
 * 128.073 von 177.188, also blieben rund 49.000 Fluege ohne Sichtbarkeits-
 * Abgleich, und die Listener NACH SetActiveFlights (`RecalculateStats`,
 * `NewVersionCheck`, die Nightly-Teile der Disposable-Module) liefen gar
 * nicht mehr. Der Nightly-Lauf war also seit Monaten halb tot, ohne dass es
 * jemandem auffiel — genau die Fehlerklasse aus dem Wochen-Cron (Fix 3).
 *
 * Die Exception bleibt trotzdem der Normalfall. Sie muss bleiben, weil
 * `DS_FreeFlightController::store()` den Rueckgabewert von `save()` nicht
 * prueft (siehe oben): ein blosses `return false` wuerde dort eine kaputte
 * Buchung als Erfolg anzeigen. Gelockert wird nur der eng umrissene Fall,
 * der NICHT vom Aufrufer verursacht wird:
 *
 *   Konsole   — kein Formular, kein Nutzer, niemand bekommt eine Meldung;
 *   Bestand   — die Zeile existiert bereits ($flight->exists);
 *   unberuehrt— dieser Save aendert `flight_number` gar nicht (!isDirty).
 *
 * Alle drei zusammen heissen: hier will jemand etwas anderes speichern und
 * stolpert nur ueber einen alten Defekt. Dann wird der Save still abgelehnt
 * (`return false` — die Zeile bleibt unveraendert, der Defekt bleibt also
 * bestehen und sichtbar) und eine Warnung geloggt, statt den ganzen Lauf zu
 * beenden. Wer in der Konsole eine 0 NEU setzt oder eine Zeile NEU anlegt,
 * bekommt weiterhin die Exception.
 *
 * ⚠ Die Signatur muss `bool` zurueckgeben. Bei `void` ignoriert Eloquent
 *   jeden Rueckgabewert, und das Abbrechen des Saves funktioniert nicht.
 */
final class FlightNumberObserver
{
    public function saving(Flight $flight): bool
    {
        $flightNumber = (int) ($flight->getAttributes()['flight_number'] ?? 0);
        if ($flightNumber > 0) {
            return true;
        }

        $callsign = trim((string) ($flight->getAttributes()['callsign'] ?? ''));

        // Altlast, die dieser Save gar nicht anfasst, und kein Nutzer davor:
        // still ablehnen statt den Aufrufer (Cron) mitzureissen. Siehe den
        // Doc-Kommentar oben — alle drei Bedingungen muessen zutreffen.
        if (app()->runningInConsole() && $flight->exists && !$flight->isDirty('flight_number')) {
            Log::warning('CoreFixes: Flight-Save mit flight_number <= 0 in der Konsole STILL ABGELEHNT (Altlast, Lauf geht weiter)', [
                'flight_id'   => $flight->id ?? null,
                'airline_id'  => $flight->airline_id ?? null,
                'route_code'  => $flight->route_code ?? null,
                'callsign'    => $callsign !== '' ? $callsign : null,
            ]);

            return false;
        }

        Log::warning('CoreFixes: Flight-Save mit flight_number <= 0 BLOCKIERT', [
            'flight_id'   => $flight->id ?? null,
            'airline_id'  => $flight->airline_id ?? null,
            'route_code'  => $flight->route_code ?? null,
            'callsign'    => $callsign !== '' ? $callsign : null,
        ]);

        // v1.2.1 (Thomas-Feedback): eine eigene, locale-aware Exception statt
        // eines nackten RuntimeException — siehe deren Doc-Kommentar. Zeigt
        // dem Piloten eine Meldung in SEINER Sprache (liest dieselbe
        // `SetActiveLanguage`-Middleware, die auch der Rest der Seite nutzt)
        // statt Laravels generischer, immer-Englisch-Standardfehlerseite.
        throw new FlightNumberInvalidException($flightNumber);
    }
}
