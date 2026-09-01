<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Http\Controllers;

use App\Http\Controllers\Frontend\ProfileController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CORE-FIX 7 — oeffentliche Profilpfade antworten mit 404 statt 500.
 *
 * `profile/{id}`, `users/{id}` und `pilots/{id}` zeigen alle auf
 * `ProfileController@show(int $id)`. Kommt dort Text an — praktisch immer
 * ein Schwachstellen-Scanner, im Log 3–4 mal taeglich ueber Wochen —, wirft
 * PHP einen TypeError, der Besucher bekommt HTTP 500 und im Protokoll steht
 * ein Fehler. Richtig ist 404: dieses Profil gibt es nicht.
 *
 * Zwei Wege wurden am 01.09.2026 auf Live ausprobiert und verworfen:
 *
 *   1. `->where('id', '[0-9]+')` auf den drei Routen. Kann hier aus zwei
 *      unabhaengigen Gruenden nicht wirken: `boot()` eines Modul-Providers
 *      laeuft, BEVOR die Routen registriert sind (`getByName()` liefert
 *      null, die Schleife laeuft lautlos ins Leere), und diese Installation
 *      faehrt `route:cache` — die `CompiledRouteCollection` matcht ueber
 *      einen vorkompilierten Matcher, den eine nachtraeglich gesetzte
 *      Einschraenkung nicht mehr erreicht.
 *
 *   2. `show($id)` ohne Parametertyp ueberschreiben. PHP lehnt das ab:
 *      "Declaration of ...::show($id) must be compatible with
 *      ProfileController::show(int $id)" — ein FATAL beim Laden der Klasse,
 *      der JEDE Seite mitnimmt (auf Live passiert, 90 Sekunden lang).
 *
 * Deshalb `callAction()`: Laravel ruft jede Controller-Methode ueber diesen
 * einen Einstieg auf. Seine Signatur ist die von `Illuminate\Routing\Controller`
 * und bleibt unveraendert — geprueft wird VOR dem Aufruf von `show()`, also
 * bevor PHP den `int`-Typ durchsetzen kann.
 */
final class ProfileControllerFix extends ProfileController
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters)
    {
        if ($method === 'show') {
            $id = reset($parameters);

            // Nur Ziffern sind eine Nutzer-ID. Alles andere ist kein Profil,
            // das es je gab — und keine 500 wert.
            if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/', $id) === 1)) {
                throw new NotFoundHttpException();
            }

            $parameters = [(int) $id];
        }

        return parent::callAction($method, $parameters);
    }
}
