<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Thrown by `FlightNumberObserver` to hard-block a Flight save with
 * `flight_number <= 0`.
 *
 * Defines its own `render()` — a documented Laravel extension point: if a
 * thrown exception has a public `render($request)` method, the framework
 * calls it directly instead of falling back to the generic default error
 * view. No core-file edit needed (`app/Exceptions/Handler.php` untouched).
 *
 * Locale-aware (Thomas-Feedback 2026-07-28: the first version's message was
 * English-only, ignoring the site's configured language): the core
 * `SetActiveLanguage` middleware (`app/Http/Middleware/SetActiveLanguage.php`)
 * already runs on every request BEFORE any controller code — it reads the
 * pilot's `lang` cookie (the site's language switcher) and calls
 * `App::setLocale()`. That has already happened by the time this exception
 * fires from inside a POST-handling controller, so `app()->getLocale()`
 * here reflects the pilot's actual chosen language — we just have to use
 * it, unlike DisposableSpecial's own flash messages ("Check Flight Number!")
 * which are hardcoded English regardless of locale.
 *
 * Kept as an inline message map (not `lang/`-file + Blade view) — no other
 * infrastructure in this small module needs that weight yet.
 */
final class FlightNumberInvalidException extends \RuntimeException
{
    public function __construct(private readonly int $badValue)
    {
        parent::__construct("CoreFixes: flight_number must be a positive integer (got {$badValue}).");
    }

    public function render(Request $request): Response
    {
        $locale = app()->getLocale();
        $messages = [
            'de' => "Ungültige Flugnummer ({$this->badValue}). Bitte eine echte Zahl größer 0 eintragen — \"0\" ist kein gültiger Wert.",
            'en' => "Invalid flight number ({$this->badValue}). Please enter a real number greater than 0 — \"0\" is not a valid value.",
        ];
        $message = $messages[$locale] ?? $messages['en'];

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        $back = $locale === 'de' ? 'Zurück' : 'Back';
        $html = '<!doctype html><html lang="'.e($locale).'"><head><meta charset="utf-8">'
            .'<title>Error</title></head><body style="font-family: system-ui, sans-serif; '
            .'max-width: 600px; margin: 80px auto; text-align: center; color: #1f2937;">'
            .'<div style="font-size: 2.5rem;">⚠️</div>'
            .'<p style="font-size: 1.1rem;">'.e($message).'</p>'
            .'<p><a href="javascript:history.back()">'.e($back).'</a></p>'
            .'</body></html>';

        return response($html, 422);
    }
}
