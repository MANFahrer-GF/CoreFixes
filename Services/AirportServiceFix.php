<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Services;

use App\Models\Airport;
use App\Services\AirportService;

/**
 * FIX 14 — Flughafen über einen fremden Code eingegeben: 500 statt des vorhandenen Flughafens.
 *
 * `AirportService::lookupAirportIfNotFound()` sucht den eingetippten Code nur als Flughafen-ID.
 * Findet es ihn nicht, fragt es vaCentral und legt das Ergebnis an. vaCentral liefert aber
 * mitunter einen Datensatz mit ANDERER ID, z. B. den Flughafen „GL-0012“ (Qaqortoq). Steht der
 * schon in der Tabelle, bricht `save()` mit „Duplicate entry“ ab (GSG 12.11.2026, Freiflug
 * eines Piloten, drei Mal HTTP 500).
 *
 * Die Korrektur nimmt in diesem Fall den vorhandenen Flughafen. Ein weich gelöschter wird
 * wiederhergestellt, sonst bliebe der Primärschlüssel belegt und der Fehler bestehen.
 */
class AirportServiceFix extends AirportService
{
    public function lookupAirportIfNotFound($icao)
    {
        $code = strtoupper((string) $icao);
        // Wie der Kern: zuerst die eingetippte ID (findWithoutFail = find ohne Ausnahme).
        if (setting('general.auto_airport_lookup') && Airport::find($code) === null) {
            // Die Flughafen-ID entsteht im Model aus `icao` (Airport::icao(), Setzer schreibt id
            // und icao gross). vaCentrals eigenes `id` ist eine interne Kennung, nicht die ID.
            $icaoTreffer = $this->lookupAirport($code)['icao'] ?? null;
            $id = is_string($icaoTreffer) ? strtoupper(trim($icaoTreffer)) : '';
            if ($id !== '' && ($vorhanden = Airport::withTrashed()->find($id)) !== null) {
                if ($vorhanden->trashed()) {
                    $vorhanden->restore();
                }

                return $vorhanden;
            }
        }

        return parent::lookupAirportIfNotFound($icao);
    }
}
