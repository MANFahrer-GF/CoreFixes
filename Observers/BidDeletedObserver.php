<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Observers;

use App\Models\Bid;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Pirep;
use App\Services\PirepService;
use Illuminate\Support\Facades\Log;

/**
 * CORE-FIX 15 — Buchung geloescht, Flug noch am Boden: laufenden PIREP abbrechen.
 *
 * Hintergrund (GSG 13.11.2026, phpVMS 7.0.13): Ein Pilot startete in AeroACARS
 * einen Flug (prefile: PIREP IN_PROGRESS, Flugzeug D-AIUM IN_USE), loeschte
 * danach auf der Webseite die Buchung und plante neu. Der Kern laesst den
 * PIREP dabei unberuehrt: er blieb in Arbeit, und das Flugzeug blieb gesperrt,
 * bis PirepService::cancel() es ueber DisposableBasics Listener freigab.
 *
 * Regel: Wird die Buchung eines Piloten geloescht und hat derselbe Pilot auf
 * DEMSELBEN Flug einen PIREP in Arbeit, der noch nicht abgehoben hat, wird
 * dieser ueber PirepService::cancel() abgebrochen (dort laufen alle Listener,
 * das Flugzeug wird frei). Ist der Flug schon in der Luft oder weiter, bleibt
 * der PIREP unangetastet.
 *
 * Warum `deleted` und nicht `deleting`: abgebrochen wird erst, wenn die
 * Buchung wirklich weg ist. Bid hat kein SoftDeletes; BidService::removeBid()
 * ruft forceDelete() am Model, das ist dort ein normales delete() und feuert
 * `deleted` genau einmal. Query-Builder-Loeschungen (`Bid::where()->delete()`,
 * z. B. BidService::removeBidForPirep nach dem Einreichen oder der
 * Ablauf-Cron) feuern kein Model-Ereignis und bleiben unberuehrt.
 */
final class BidDeletedObserver
{
    /**
     * Status vor dem Abheben (app/Models/Enums/PirepStatus.php): alles bis
     * einschliesslich Rollen (TXI) und Rueckkehr zum Stand (GRT). Ab TAKEOFF
     * (TOF, Startlauf) zaehlt der Flug als gestartet, ebenso PAUSED, EMERG_DESCENT
     * und DIVERTED, weil diese Status auch in der Luft vorkommen.
     */
    public const VOR_DEM_START = [
        PirepStatus::INITIATED,
        PirepStatus::SCHEDULED,
        PirepStatus::BOARDING,
        PirepStatus::RDY_START,
        PirepStatus::PUSHBACK_TOW,
        PirepStatus::DEPARTED,
        PirepStatus::RDY_DEICE,
        PirepStatus::STRT_DEICE,
        PirepStatus::GRND_RTRN,
        PirepStatus::TAXI,
    ];

    public function deleted(Bid $bid): void
    {
        // Verwaiste Buchung ohne (aktiven) Flug: Kern und Disposable raeumen
        // sie beim Seitenaufruf weg. Das ist keine Entscheidung des Piloten.
        if ($bid->flight === null) {
            return;
        }

        $pireps = Pirep::where('user_id', $bid->user_id)
            ->where('flight_id', $bid->flight_id)
            ->where('state', PirepState::IN_PROGRESS)
            ->whereIn('status', self::VOR_DEM_START)
            ->get();

        foreach ($pireps as $pirep) {
            try {
                app(PirepService::class)->cancel($pirep);
            } catch (\Throwable $e) {
                Log::error('CoreFixes | Buchung '.$bid->id.' geloescht, PIREP '.$pirep->id.' liess sich nicht abbrechen: '.$e->getMessage());
                continue;
            }

            Log::info('CoreFixes | Buchung '.$bid->id.' geloescht, PIREP '.$pirep->id.' (Status '.$pirep->status.') vor dem Start abgebrochen');

            if (request()->hasSession()) {
                flash()->warning(__('corefixes::bid.flug_abgebrochen'));
            }
        }
    }
}
