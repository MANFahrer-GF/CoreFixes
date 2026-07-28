<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Observers;

use App\Models\Flight;
use Illuminate\Support\Facades\Log;

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
 * UX-Kompromiss (bewusst akzeptiert): DisposableSpecial duerfen wir laut
 * Vorgabe nicht anfassen, koennen also keine huebsche "Check Flight Number!"-
 * Flash-Meldung wie bei den anderen Validierungen in diesem Formular zeigen.
 * Der Pilot sieht stattdessen Laravels Standard-Fehlerseite (APP_DEBUG=false
 * auf Live verifiziert -> keine Stacktrace/Info-Leaks, nur eine generische
 * Fehlerseite). Besser eine haessliche, aber EHRLICHE Fehlerseite als eine
 * huebsche, aber FALSCHE Erfolgsmeldung.
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
 */
final class FlightNumberObserver
{
    public function saving(Flight $flight): void
    {
        $flightNumber = (int) ($flight->getAttributes()['flight_number'] ?? 0);
        if ($flightNumber > 0) {
            return;
        }

        $callsign = trim((string) ($flight->getAttributes()['callsign'] ?? ''));

        Log::warning('CoreFixes: Flight-Save mit flight_number <= 0 BLOCKIERT', [
            'flight_id'   => $flight->id ?? null,
            'airline_id'  => $flight->airline_id ?? null,
            'route_code'  => $flight->route_code ?? null,
            'callsign'    => $callsign !== '' ? $callsign : null,
        ]);

        throw new \RuntimeException(
            'CoreFixes: flight_number must be a positive integer (got '.$flightNumber.'). '
            .'"0" is not a valid flight number — please enter a real one.'
        );
    }
}
