<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Providers;

use App\Models\Flight;
use App\Models\Pirep;
use Illuminate\Support\ServiceProvider;
use Modules\CoreFixes\Observers\FlightNumberObserver;
use Modules\CoreFixes\Observers\PirepBlockTimeObserver;
use Modules\CoreFixes\Services\DisposableCronFix;
use Modules\DisposableSpecial\Services\DS_CronServices;

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
 *   2. FlightNumberObserver — blockiert (wirft eine Exception) Flight-Saves
 *      mit `flight_number <= 0` (typisch: DisposableSpecial-Freiflug-
 *      Formular). Keine stille `return false` — siehe der Klasse eigener
 *      Doc-Kommentar, warum das hier eine falsche Erfolgsmeldung + eine
 *      Bid gegen einen nie gespeicherten Flight erzeugen wuerde.
 *   3. DisposableCronFix — DisposableSpecials Wochen-Cron sprengte an zwei
 *      Stellen das MySQL-Platzhalterlimit (Fehler 1390) und war dadurch
 *      14 Wochen still tot. Gleiches Prinzip wie oben, nur eine Ebene hoeher:
 *      statt die Moduldatei zu patchen (weg beim naechsten Modul-Update),
 *      wird die Service-Klasse im Container ausgetauscht.
 */
final class CoreFixesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Pirep::observe(PirepBlockTimeObserver::class);
        Flight::observe(FlightNumberObserver::class);
    }

    public function register(): void
    {
        // Alle vier Aufrufe in DisposableSpecials Gen_Cron holen den Dienst ueber
        // app(DS_CronServices::class) — diese eine Bindung deckt sie alle ab.
        $this->app->bind(DS_CronServices::class, DisposableCronFix::class);
    }
}
