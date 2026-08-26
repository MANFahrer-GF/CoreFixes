<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Observers;

use App\Models\User;

/**
 * CORE-FIX 6 — Persoenliches Rufzeichen normalisieren.
 *
 * Hintergrund (Thomas, 2026-08-22): `users.callsign` haelt das persoenliche
 * Rufzeichen des Piloten. PaxStudio baut daraus auf der Dispatch-Seite die
 * Auswahl "ICAO + persoenliches Rufzeichen" (`dispatch.blade.php`:367) und
 * uebergibt sie als `callsign` an SimBrief. Bis jetzt war das Feld NUR im
 * Adminbereich pflegbar (`CHHubManager/Resources/views/admin/users/fields.blade.php`);
 * der Pilot selbst kam nicht heran. Mit dem neuen Feld im Profilformular
 * kann er es nun setzen — und braucht damit eine Absicherung.
 *
 * Warum ein Observer und nicht eine Regel im ProfileController: Der Controller
 * ist eine Core-Datei (`app/Http/Controllers/Frontend/ProfileController.php`)
 * und waere nach jedem phpVMS-Update wieder ueberschrieben. Ausserdem uebernimmt
 * er `$request->all()` und entfernt daraus nur einzelne technische Felder —
 * eine Validierungsregel dort deckt den Adminbereich und den Import gar nicht
 * mit ab. Der Observer greift an JEDER Schreibstelle.
 *
 * Bewusst normalisierend statt blockierend: Ein unsauberes Rufzeichen ist
 * kein Datenverlust und nichts Sicherheitskritisches — anders als beim
 * FlightNumberObserver, wo ein stiller Fehlschlag eine kaputte Buchung als
 * Erfolg aussehen liesse. Hier ist Zurechtruecken die freundlichere Antwort.
 *
 * Regeln: Grossbuchstaben, nur A-Z und 0-9, hoechstens 4 Zeichen (die
 * Model-Regel in `User::$rules` sagt `nullable|max:4`), leer wird zu NULL.
 * Vier Zeichen, weil PaxStudio das ICAO-Kuerzel davorsetzt und ein
 * ATC-Rufzeichen darueber hinaus nicht mehr sinnvoll ist.
 */
final class UserCallsignObserver
{
    public function saving(User $user): void
    {
        $attribute = $user->getAttributes();

        if (!array_key_exists('callsign', $attribute)) {
            return;
        }

        $roh = (string) ($attribute['callsign'] ?? '');
        $sauber = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($roh)));
        $sauber = substr((string) $sauber, 0, 4);

        $user->callsign = $sauber === '' ? null : $sauber;
    }
}
