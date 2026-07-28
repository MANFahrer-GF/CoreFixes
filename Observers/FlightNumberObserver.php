<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Observers;

use App\Models\Flight;
use Illuminate\Support\Facades\Log;

/**
 * CORE-FIX 2 — Flight-Number-Data-Hygiene (Freiflug/DisposableSpecial).
 *
 * Hintergrund (Pilot-Befund Ralf T., GSG0016, 2026-07-28): das DisposableSpecial-
 * Freiflug-Formular akzeptiert eine "0" im Flight-Number-Feld als gueltige
 * Eingabe (nur ein LEERES Feld wird abgelehnt) und speichert sie unveraendert.
 * `flights.flight_number` ist `int(10) unsigned NOT NULL` — eine echte NULL-
 * Korrektur ist hier NICHT moeglich (anders als bei den PIREP-Block-Zeiten
 * oben): ein `Flight::saving`-Observer, der auf `null` normalisiert, wuerde
 * den Save entweder hart brechen (NOT NULL) oder (schlimmer) klaglos wieder
 * auf 0 zurueckfallen (dieser Server faehrt `sql_mode` OHNE
 * STRICT_TRANS_TABLES). Ausserdem darf `flight_number` NICHT stillschweigend
 * auf `null`/leer umgeschrieben werden, weil das ueber die phpVMS-API als
 * `flight_number: null` bei JEDEM Consumer ankaeme — der AeroACARS-Client
 * (aktuell im Feld installiert, alle Versionen) deserialisiert das Feld als
 * nicht-optionalen String und wuerde bei `null` die KOMPLETTE Bids/Flights-
 * Antwort nicht mehr parsen koennen (schlimmere Regression als der
 * gemeldete Anzeige-Bug). Aus demselben Grund NICHT blockieren
 * (`return false` / Exception): DisposableSpecial duerfen wir laut Vorgabe
 * nicht anfassen, und ein hart abgewiesener Save waere dort nur eine haessliche
 * 500-Seite statt einer Validierungsmeldung — verstiesse ausserdem gegen das
 * Grundprinzip dieses Moduls ("ein Fix darf niemals eine Einreichung kippen").
 *
 * Die tatsaechliche Reparatur lebt im AeroACARS-Client: `callsign` wird VOR
 * `flight_number` bevorzugt angezeigt (`resolveFlightIdent()`, `src/lib/
 * callsign.ts`) — exakt dieselbe Prioritaet wie phpVMS' eigener
 * `Flight::atc()`-Accessor (`app/Models/Flight.php`).
 *
 * Was dieser Observer beitraegt: NUR Sichtbarkeit, keine Datenaenderung.
 * Er protokolliert (nicht blockierend, try/catch), wenn ein Flight mit
 * `flight_number <= 0` gespeichert wird — mit oder ohne Callsign — damit
 * Admins wiederkehrende Faelle wie diesen (Ralf hatte ihn schon zweimal,
 * 23.07. + 28.07.2026) proaktiv im Log sehen statt erst per Discord-Meldung
 * davon zu erfahren.
 */
final class FlightNumberObserver
{
    public function saving(Flight $flight): void
    {
        try {
            $this->logIfSuspicious($flight);
        } catch (\Throwable $e) {
            // Ein Fix darf niemals einen Flight-Save kippen.
            Log::error('CoreFixes: Flight-Number-Hygiene-Check fehlgeschlagen', [
                'flight_id' => $flight->id ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function logIfSuspicious(Flight $flight): void
    {
        $flightNumber = (int) ($flight->getAttributes()['flight_number'] ?? 0);
        if ($flightNumber > 0) {
            return;
        }

        $callsign = trim((string) ($flight->getAttributes()['callsign'] ?? ''));

        Log::info('CoreFixes: Flight gespeichert mit flight_number <= 0', [
            'flight_id'   => $flight->id ?? null,
            'airline_id'  => $flight->airline_id ?? null,
            'route_code'  => $flight->route_code ?? null,
            'callsign'    => $callsign !== '' ? $callsign : null,
            // Ohne Callsign gibt es fuer keinen Consumer (Client, Admin-UI,
            // Export) ueberhaupt einen Identifier fuer diesen Flight —
            // das ist der einzige wirklich problematische Unterfall.
            'no_identifier_at_all' => $callsign === '',
        ]);
    }
}
