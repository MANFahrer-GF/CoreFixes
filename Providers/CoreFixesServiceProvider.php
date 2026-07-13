<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Providers;

use App\Models\Pirep;
use Illuminate\Support\ServiceProvider;
use Modules\CoreFixes\Observers\PirepBlockTimeObserver;

/**
 * CoreFixes — haelt Korrekturen an phpVMS-Core-Bugs update-fest.
 *
 * Grundsatz: KEINE Core-Datei anfassen. Jedes phpVMS-Update ueberschreibt
 * `app/`-Dateien, und ein handgepatchter Core-Bug ist danach still wieder da
 * (genau das ist auf GSG schon zweimal passiert). Stattdessen haengt sich dieses
 * Modul von aussen in die Model-Events und korrigiert das VERHALTEN.
 *
 * Registrierte Fixes:
 *   1. PirepBlockTimeObserver — block_off_time/block_on_time bleiben NULL, weil
 *      der Core-Fallback in PirepService::create() am CarbonCast scheitert
 *      (NULL liest sich als "jetzt" und ist damit truthy).
 */
final class CoreFixesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Pirep::observe(PirepBlockTimeObserver::class);
    }

    public function register(): void
    {
        //
    }
}
