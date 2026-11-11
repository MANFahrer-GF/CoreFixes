<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORE-FIX 10 — `?limit=` der API darf weiter bis 100 gehen.
 *
 * phpVMS 7.0.13 deckelt in `App\Contracts\Repository::paginate()` jede
 * Seitengroesse auf `repository.pagination.limit` (bei GSG 20). Bis 7.0.11
 * galt ein ausdruecklich angefragtes `?limit=` ungebremst.
 *
 * AeroACARS (alle Versionen im Feld) holt die Flotte mit
 * `/api/fleet?limit=100&page=N` und hoert auf zu blaettern, sobald eine
 * Seite weniger als 100 Eintraege hat. Mit dem Deckel 20 saehe der Pilot
 * 20 von 815 Subflotten. Die Clients lassen sich nicht nachtraeglich
 * aendern, also muss der Server die 100 weiter liefern.
 *
 * Angehoben wird nur fuer diese eine Anfrage und nur, wenn sie selbst ein
 * groesseres `limit` nennt. Die Standardgroesse (ohne `limit`) bleibt 20,
 * und mehr als 100 gibt es nicht — der Schutz des Kerns vor `limit=100000`
 * bleibt also erhalten.
 */
class ApiSeitengroesse
{
    public const OBERGRENZE = 100;

    public function handle(Request $request, Closure $next): Response
    {
        $limit = $request->query('limit');

        if (is_string($limit) && ctype_digit($limit)) {
            $gewuenscht = min((int) $limit, self::OBERGRENZE);

            if ($gewuenscht > (int) config('repository.pagination.limit', 50)) {
                config(['repository.pagination.limit' => $gewuenscht]);
            }
        }

        return $next($request);
    }
}
