<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Observers;

use App\Models\Pirep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CORE-FIX 1 — PIREP-Block-Zeiten.
 *
 * phpVMS WILL die Block-Zeiten selbst nachtragen (PirepService::create()):
 *
 *     if (!$pirep->block_on_time)  { $pirep->block_on_time  = $pirep->submitted_at ?: now(); }
 *     if (!$pirep->block_off_time && $pirep->flight_time > 0) {
 *         $pirep->block_off_time = $pirep->block_on_time->subMinutes($pirep->flight_time);
 *     }
 *
 * Beide Guards feuern NIE. Die Spalten sind mit `App\Casts\CarbonCast` gecastet,
 * und dessen `get()` macht aus einem NULL-Wert `new Carbon(null)` — also die
 * AKTUELLE UHRZEIT, die immer truthy ist. Ein PIREP ohne Block-Zeiten behaelt sie
 * darum fuer immer als NULL in der DB, waehrend jeder Leser (Model, API, Modul)
 * stattdessen "jetzt" serviert bekommt. Auf GSG betraf das JEDEN AeroACARS-PIREP:
 * `block_off_time` war zu 0 % gefuellt, bei vmsACARS/smartCARS zu 100 %.
 *
 * Dieser Observer macht dieselbe Reparatur, die der Core beabsichtigt — aber er
 * prueft den ROHWERT (`getAttributes()`), nicht den vom Cast verfaelschten
 * Getter. Er laeuft beim Speichern, also unabhaengig davon, welcher ACARS-Client
 * oder welcher Code-Pfad den PIREP anlegt.
 *
 * Idempotent und konservativ: gesetzte Werte werden NIE ueberschrieben, und ohne
 * belastbare Grundlage (keine Flugzeit) wird nichts erfunden.
 */
final class PirepBlockTimeObserver
{
    public function saving(Pirep $pirep): void
    {
        try {
            $this->fillBlockTimes($pirep);
        } catch (\Throwable $e) {
            // Ein Fix darf niemals eine PIREP-Einreichung kippen.
            Log::error('CoreFixes: Block-Zeiten-Fix fehlgeschlagen', [
                'pirep_id' => $pirep->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function fillBlockTimes(Pirep $pirep): void
    {
        $blockOn  = $this->rawValue($pirep, 'block_on_time');
        $blockOff = $this->rawValue($pirep, 'block_off_time');

        // Block-on: ohne Ankunftszeit taugt die Einreichzeit als Naeherung —
        // exakt die Absicht des Cores. Nur wenn wirklich nichts da ist.
        if ($blockOn === null) {
            $submitted = $this->rawValue($pirep, 'submitted_at');
            if ($submitted !== null) {
                $pirep->block_on_time = Carbon::parse($submitted);
                $blockOn              = $submitted;
            }
        }

        // Block-off: aus Ankunft minus Flugzeit zurueckrechnen. Ohne Flugzeit
        // gibt es keine ehrliche Grundlage — dann bleibt die Spalte NULL,
        // statt eine Zahl zu erfinden.
        if ($blockOff === null && $blockOn !== null) {
            $flightTime = (int) ($pirep->getAttributes()['flight_time'] ?? 0);
            if ($flightTime > 0) {
                $pirep->block_off_time = Carbon::parse($blockOn)->subMinutes($flightTime);
            }
        }
    }

    /**
     * Rohwert aus dem Attribut-Array — umgeht den CarbonCast, der aus NULL
     * "jetzt" macht. Genau hier scheitert der Core.
     */
    private function rawValue(Pirep $pirep, string $key): ?string
    {
        $value = $pirep->getAttributes()[$key] ?? null;

        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return (string) $value;
    }
}
