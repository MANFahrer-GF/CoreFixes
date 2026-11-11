<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Listeners;

use App\Events\ProfileUpdated;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * CORE-FIX 12 — das Rufzeichen aus dem Profilformular wird weiter gespeichert.
 *
 * Das SPTheme-Profilformular hat seit 23.08.2026 das Feld `callsign`.
 * Bis phpVMS 7.0.11 ging es ueber `$request->all()` in die Nutzerzeile.
 * 7.0.13 nimmt nur noch eine feste Feldliste (`$request->only([...])`),
 * `callsign` steht nicht darauf — die Aenderung ginge still verloren.
 *
 * `ProfileUpdated` feuert der Kern erst NACH bestandener Pruefung und
 * gespeichertem Profil. Scheitert das Formular, laeuft dieser Hoerer nicht
 * und das Rufzeichen bleibt, wie es war. Saeubern (Grossbuchstaben, Ziffern,
 * hoechstens 4 Zeichen) uebernimmt wie bisher der `UserCallsignObserver`.
 */
final class ProfilRufzeichen
{
    public function handle(ProfileUpdated $event): void
    {
        $request = request();

        if (! $request->routeIs('frontend.profile.update') || ! $request->exists('callsign')) {
            return;
        }

        $rufzeichen = $request->input('callsign');
        if ($rufzeichen !== null && ! is_string($rufzeichen)) {
            return;
        }

        // Nur das eigene Profil, und frisch geladen: das Modell im Ereignis
        // stammt von vor dem Speichern.
        $user = User::find(Auth::id());
        if ($user === null || $user->id !== $event->user->id) {
            return;
        }

        $user->callsign = $rufzeichen;
        $user->save();
    }
}
