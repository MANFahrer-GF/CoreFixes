<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Tests\Feature;

use App\Contracts\AirportLookup;
use App\Models\Airport;
use App\Services\AirportService;
use Illuminate\Support\Facades\Cache;
use Modules\CoreFixes\Services\AirportServiceFix;
use Tests\TestCase;

/**
 * FIX 14: vaCentral liefert für einen fremden Code einen schon vorhandenen Flughafen
 * mit anderer ID (GSG 12.11.2026: Code → „GL-0012“, Freiflug brach mit 500 ab).
 * Lauf: im Ordner ~/Claude/phpvms-7
 * `vendor/bin/phpunit <pfad>/CoreFixes/tests/Feature/AirportServiceFixTest.php`
 */
final class AirportServiceFixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(AirportServiceFix::class)) {
            require_once __DIR__.'/../../Services/AirportServiceFix.php';
        }
        Cache::flush();
        $this->updateSetting('general.auto_airport_lookup', '1');
        // vaCentral-Antwort in der live gemessenen Form (12.11.2026, BGQQ): `id` ist vaCentrals
        // interne Kennung, die Flughafen-ID entsteht aus `icao`. Hier: anderer Code als eingetippt.
        $this->app->instance(AirportLookup::class, new class() extends AirportLookup {
            public function getAirport($icao)
            {
                return $icao === 'JJUX' ? (object) [
                    'icao' => 'gl-0012', 'iata' => 'JJU', 'name' => 'Qaqortoq Airport', 'id' => 'ZiKA6qQkJiDN',
                    'country' => 'GL', 'timezone' => 'America/Godthab', 'lat' => 60.76383, 'lon' => -46.065001,
                ] : null;
            }
        });
    }

    private function dienst(): AirportService
    {
        return $this->app->make(AirportServiceFix::class);
    }

    public function test_kern_bricht_ohne_fix(): void
    {
        Airport::factory()->create(['id' => 'GL-0012', 'icao' => 'GL-0012', 'iata' => 'JJU']);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->app->make(AirportService::class)->lookupAirportIfNotFound('JJUX');
    }

    public function test_vorhandener_flughafen_mit_anderer_id_wird_genommen(): void
    {
        Airport::factory()->create(['id' => 'GL-0012', 'icao' => 'GL-0012', 'iata' => 'JJU', 'name' => 'Qaqortoq Airport']);

        $flughafen = $this->dienst()->lookupAirportIfNotFound('jjux');

        $this->assertSame('GL-0012', $flughafen->id);
        $this->assertSame(1, Airport::where('id', 'GL-0012')->count());
    }

    public function test_weich_geloeschter_flughafen_wird_wiederhergestellt(): void
    {
        Airport::factory()->create(['id' => 'GL-0012', 'icao' => 'GL-0012', 'iata' => 'JJU'])->delete();

        $flughafen = $this->dienst()->lookupAirportIfNotFound('JJUX');

        $this->assertSame('GL-0012', $flughafen->id);
        $this->assertNotNull(Airport::find('GL-0012'));
    }

    public function test_neuer_flughafen_wird_wie_im_kern_angelegt(): void
    {
        $flughafen = $this->dienst()->lookupAirportIfNotFound('JJUX');

        $this->assertSame('GL-0012', $flughafen->id);
        $this->assertSame('Qaqortoq Airport', Airport::find('GL-0012')->name);
    }

    public function test_eingetippte_id_vorhanden_bleibt_wie_im_kern(): void
    {
        Airport::factory()->create(['id' => 'EDDF', 'icao' => 'EDDF']);

        $this->assertSame('EDDF', $this->dienst()->lookupAirportIfNotFound('eddf')->id);
    }

    public function test_unbekannter_code_bleibt_null(): void
    {
        $this->assertNull($this->dienst()->lookupAirportIfNotFound('ZZZZ'));
    }
}
