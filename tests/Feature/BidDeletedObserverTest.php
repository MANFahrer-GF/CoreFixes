<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Tests\Feature;

use App\Events\PirepCancelled;
use App\Models\Aircraft;
use App\Models\Bid;
use App\Models\Enums\AircraftState;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\User;
use App\Services\BidService;
use Illuminate\Support\Facades\Event;
use Modules\CoreFixes\Observers\BidDeletedObserver;
use Modules\CoreFixes\Providers\CoreFixesServiceProvider;
use Tests\TestCase;

/**
 * FIX 15: Buchung geloescht, Flug noch am Boden → PIREP abbrechen
 * (GSG 13.11.2026: D-AIUM blieb nach dem Loeschen der Buchung gesperrt).
 * Lauf: im Ordner ~/Claude/phpvms-7
 * `vendor/bin/phpunit <pfad>/CoreFixes/tests/Feature/BidDeletedObserverTest.php`
 */
final class BidDeletedObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Die Klassen dieses Moduls kommen aus diesem Checkout, nicht aus der Kopie
        // unter phpvms-7/modules (die Module werden im Test erst nach dem Boot aktiv).
        $basis = realpath(__DIR__.'/../..');
        spl_autoload_register(static function (string $klasse) use ($basis): void {
            if (str_starts_with($klasse, 'Modules\\CoreFixes\\')) {
                $datei = $basis.'/'.str_replace('\\', '/', substr($klasse, strlen('Modules\\CoreFixes\\'))).'.php';
                if (is_file($datei)) {
                    require_once $datei;
                }
            }
        }, true, true);
        $this->assertStringStartsWith($basis, (new \ReflectionClass(BidDeletedObserver::class))->getFileName());

        // Die echte Registrierung des Moduls (Provider::boot), nicht ein eigenes observe().
        (new CoreFixesServiceProvider($this->app))->boot();

        // Live parkt DisposableBasics Listener `Pirep_Cancelled` das Flugzeug (bei
        // dbasic.acstate_control). Das Modul ist im Test nicht aktiv; dieser Ersatz tut dasselbe.
        Event::listen(PirepCancelled::class, static function (PirepCancelled $e): void {
            $e->pirep->aircraft->update(['state' => AircraftState::PARKED]);
        });
    }

    /** @return array{0: User, 1: Flight, 2: Bid, 3: Pirep} */
    private function laufenderFlug(string $status, int $state = PirepState::IN_PROGRESS): array
    {
        $user = User::factory()->create();
        $flight = Flight::factory()->create();
        $aircraft = Aircraft::factory()->create(['state' => AircraftState::IN_USE]);
        $bid = Bid::create(['user_id' => $user->id, 'flight_id' => $flight->id]);
        $pirep = Pirep::factory()->create([
            'user_id'     => $user->id,
            'flight_id'   => $flight->id,
            'aircraft_id' => $aircraft->id,
            'state'       => $state,
            'status'      => $status,
        ]);

        return [$user, $flight, $bid, $pirep];
    }

    public static function vorDemStart(): array
    {
        // Die erwartete Liste steht hier bewusst ausgeschrieben, nicht aus der Konstante gelesen.
        $liste = ['INI', 'SCH', 'BST', 'RDT', 'PBT', 'OFB', 'DIR', 'DIC', 'GRT', 'TXI'];

        return array_combine($liste, array_map(static fn (string $s) => [$s], $liste));
    }

    /** @dataProvider vorDemStart */
    public function test_vor_dem_start_wird_abgebrochen_und_flugzeug_frei(string $status): void
    {
        [, , $bid, $pirep] = $this->laufenderFlug($status);

        $bid->delete();

        $pirep->refresh();
        $this->assertSame(PirepState::CANCELLED, (int) $pirep->state);
        $this->assertSame(PirepStatus::CANCELLED, $pirep->status);
        $this->assertSame(AircraftState::PARKED, (int) $pirep->aircraft->fresh()->state);
    }

    public function test_bidservice_remove_bid_feuert_einmal(): void
    {
        [$user, $flight, , $pirep] = $this->laufenderFlug(PirepStatus::BOARDING);
        $abbrueche = 0;
        Event::listen(PirepCancelled::class, static function () use (&$abbrueche): void {
            $abbrueche++;
        });

        app(BidService::class)->removeBid($flight, $user);

        $this->assertSame(PirepState::CANCELLED, (int) $pirep->fresh()->state);
        $this->assertSame(1, $abbrueche);
    }

    public static function inDerLuft(): array
    {
        return [
            'TOF' => [PirepStatus::TAKEOFF], 'TKO' => [PirepStatus::AIRBORNE], 'ENR' => [PirepStatus::ENROUTE],
            'PSD' => [PirepStatus::PAUSED], 'EMG' => [PirepStatus::EMERG_DESCENT], 'DV' => [PirepStatus::DIVERTED],
            'LAN' => [PirepStatus::LANDED], 'ARR' => [PirepStatus::ARRIVED],
        ];
    }

    /** @dataProvider inDerLuft */
    public function test_gestarteter_flug_bleibt_unveraendert(string $status): void
    {
        [, , $bid, $pirep] = $this->laufenderFlug($status);

        $bid->delete();

        $this->assertSame(PirepState::IN_PROGRESS, (int) $pirep->fresh()->state);
        $this->assertSame(AircraftState::IN_USE, (int) $pirep->aircraft->fresh()->state);
    }

    public function test_anderer_pilot_bleibt_unveraendert(): void
    {
        [, $flight, , $pirep] = $this->laufenderFlug(PirepStatus::BOARDING);
        $fremd = Bid::create(['user_id' => User::factory()->create()->id, 'flight_id' => $flight->id]);

        $fremd->delete();

        $this->assertSame(PirepState::IN_PROGRESS, (int) $pirep->fresh()->state);
    }

    public function test_anderer_flug_bleibt_unveraendert(): void
    {
        [$user, , , $pirep] = $this->laufenderFlug(PirepStatus::BOARDING);
        $andere = Bid::create(['user_id' => $user->id, 'flight_id' => Flight::factory()->create()->id]);

        $andere->delete();

        $this->assertSame(PirepState::IN_PROGRESS, (int) $pirep->fresh()->state);
    }

    public function test_eingereichter_pirep_bleibt_unveraendert(): void
    {
        [, , $bid, $pirep] = $this->laufenderFlug(PirepStatus::ARRIVED, PirepState::PENDING);

        $bid->delete();

        $this->assertSame(PirepState::PENDING, (int) $pirep->fresh()->state);
    }

    public function test_verwaiste_buchung_ohne_flug_bricht_nichts_ab(): void
    {
        [, $flight, $bid, $pirep] = $this->laufenderFlug(PirepStatus::BOARDING);
        $flight->delete();

        $bid->refresh()->delete();

        $this->assertSame(PirepState::IN_PROGRESS, (int) $pirep->fresh()->state);
    }

    public function test_hinweis_im_web_kontext(): void
    {
        [, , $bid] = $this->laufenderFlug(PirepStatus::BOARDING);
        $this->app->setLocale('de');
        $anfrage = request();
        $anfrage->setLaravelSession($this->app['session.store']);

        $bid->delete();

        $meldung = session('flash_notification')->first();
        $this->assertSame('warning', $meldung->level);
        $this->assertStringContainsString('abgebrochen', $meldung->message);
    }
}
