<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Services;

use App\Models\Enums\AirframeSource;
use App\Models\SimBriefAirframe;
use App\Services\SimBriefService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Räumt beim SimBrief-Import auf, was SimBrief nicht mehr hat.
 *
 * # Das Problem
 *
 * `SimBriefService::getAircraftAndAirframes()` benutzt `updateOrCreate` und
 * **löscht nie**. Was SimBrief entfernt, bleibt bei uns für immer stehen —
 * und ein Pilot, dessen Flugzeug an so ein Profil gebunden ist, bekommt beim
 * Dispatch „Fatal Error: Unknown aircraft type" und gar keinen OFP.
 *
 * Gemessen am 30.08.2026: 52 von 540 importierten Profilen waren tot, bei
 * B763 sogar ALLE bis auf „Default" (gemeldet an OY-SYA).
 *
 * # ⚠ Was hier NIEMALS gelöscht werden darf
 *
 * Profile mit `source = AirframeSource::INTERNAL` sind unsere EIGENEN, im
 * privaten GSG-SimBrief-Konto angelegt. Die öffentliche Liste
 * (`inputs.airframes.json`) enthält nur öffentlich geteilte Profile — private
 * eines Kontos stehen dort grundsätzlich nicht. Wer sie an dieser Liste misst,
 * hält alle 74 für tot und löscht die halbe Flotte.
 *
 * Deshalb: Es wird ausschliesslich `AirframeSource::SIMBRIEF` angefasst, und
 * auch das nur, wenn die Liste überhaupt plausibel geladen wurde.
 */
final class SimBriefAirframeAufraeumer extends SimBriefService
{
    /**
     * Wie viele Airframes die öffentliche Liste mindestens enthalten muss,
     * bevor wir ihr glauben.
     *
     * ⚠ Ohne diesen Riegel macht eine halb geladene oder leere Antwort aus
     * dem Aufräumen einen Kahlschlag: Alles wäre „nicht mehr in der Liste".
     * Am 30.08.2026 waren es 491.
     */
    private const MINDESTUMFANG = 200;

    public function getAircraftAndAirframes(): void
    {
        parent::getAircraftAndAirframes();

        $lebende = $this->lebendeKennungen();
        if ($lebende === null) {
            return;
        }

        // Nur importierte Profile MIT Kennung. Die „Default"-Zeilen haben
        // keine und werden über das Muster selbst aufgelöst — sie fallen
        // weg, sobald SimBrief das Muster nicht mehr führt, und das ist ein
        // anderer Fall als ein entferntes Profil.
        $tot = SimBriefAirframe::query()
            ->where('source', AirframeSource::SIMBRIEF)
            ->whereNotNull('airframe_id')
            ->where('airframe_id', '<>', '')
            ->whereNotIn('airframe_id', $lebende)
            ->get(['id', 'icao', 'name', 'airframe_id']);

        if ($tot->isEmpty()) {
            Log::info('SimBrief | Aufraeumen: keine toten Profile');

            return;
        }

        foreach ($tot as $eintrag) {
            Log::info(sprintf(
                'SimBrief | Aufraeumen: entferne totes Profil %s / %s (%s)',
                $eintrag->icao,
                $eintrag->name,
                $eintrag->airframe_id
            ));
        }

        SimBriefAirframe::query()
            ->where('source', AirframeSource::SIMBRIEF)
            ->whereIn('id', $tot->pluck('id'))
            ->delete();

        Log::info('SimBrief | Aufraeumen: '.$tot->count().' tote Profile entfernt');
    }

    /**
     * Die Kennungen aller öffentlich geteilten Profile, in derselben
     * Schreibweise wie in der Datenbank (`pilot_id . '_' . airframe_id`).
     *
     * `null` heisst „konnte nicht verlaesslich geladen werden" — dann wird
     * NICHT aufgeräumt.
     */
    private function lebendeKennungen(): ?array
    {
        $antwort = Http::timeout(60)->get(config('phpvms.simbrief_airframes_url'));
        if (! $antwort->ok()) {
            Log::warning('SimBrief | Aufraeumen uebersprungen: Liste nicht abrufbar');

            return null;
        }

        $kennungen = [];
        foreach ((array) $antwort->json() as $muster) {
            foreach ($muster['airframes'] ?? [] as $af) {
                if (! empty($af['airframe_id'])) {
                    $kennungen[] = $af['pilot_id'].'_'.$af['airframe_id'];
                }
            }
        }

        if (count($kennungen) < self::MINDESTUMFANG) {
            Log::warning(
                'SimBrief | Aufraeumen uebersprungen: Liste unplausibel klein ('
                .count($kennungen).' < '.self::MINDESTUMFANG.')'
            );

            return null;
        }

        return $kennungen;
    }
}
