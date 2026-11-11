<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Http\Middleware;

use App\Models\Enums\PirepSource;
use App\Models\Pirep;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORE-FIX 11 — ein manuell beendeter Flug bleibt als „manuell“ markiert.
 *
 * AeroACARS setzt bei `flight_end_manual` vor der Abgabe per
 * `POST /api/pireps/{id}/update` `source: 0` (MANUAL). Daran haengt, ob
 * `PirepService::submit()` den Flug automatisch annimmt oder zur Pruefung
 * liegen laesst; DisposableSpecial rechnet ausserdem Bezahlung und Wartung
 * nach dieser Quelle.
 *
 * phpVMS 7.0.13 verwirft `source` in `update` und `file`. Das ist richtig
 * fuer die Gegenrichtung (ein von Hand eingetragener Flug darf sich nicht
 * zum ACARS-Flug erklaeren), nimmt aber auch die ehrliche Selbstauskunft
 * „ich habe von Hand beendet“ weg.
 *
 * Erlaubt wird deshalb genau eine Richtung: ACARS → MANUAL, am eigenen
 * PIREP, und nur wenn der Kern die Anfrage selbst angenommen hat (Besitzer,
 * nicht storniert, nicht abgeschlossen — das prueft der Controller, darum
 * laeuft diese Schicht NACH ihm). MANUAL → ACARS bleibt verworfen.
 */
class ManuelleQuelle
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/pireps/*/update') || ! $response->isSuccessful()) {
            return $response;
        }

        $quelle = $request->input('source');
        if (! is_numeric($quelle) || (int) $quelle !== PirepSource::MANUAL) {
            return $response;
        }

        $pilot = Auth::id();
        $pirep = $request->route('pirep_id');
        if ($pilot === null || ! is_string($pirep) || $pirep === '') {
            return $response;
        }

        Pirep::where('id', $pirep)
            ->where('user_id', $pilot)
            ->where('source', PirepSource::ACARS)
            ->update(['source' => PirepSource::MANUAL]);

        return $response;
    }
}
