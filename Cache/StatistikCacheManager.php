<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Log;

/**
 * CacheManager, dessen Standard-Store `corefixes` ist — OHNE die Config anzufassen.
 *
 * Warum nicht einfach `config(['cache.default' => …])`: phpVMS ruft
 * `config:cache` selbst auf (Admin → Wartung → Cache „application“/„all“,
 * `MaintenanceController.php:55`; jede Modul-Installation, `ModuleService.php:243`).
 * Dabei wuerde unser Laufzeitwert in `bootstrap/cache/config.php` eingefroren —
 * und ohne dieses Modul gaebe es danach den Treiber `corefixes` nicht mehr:
 * HTTP 500 auf jeder Seite. So bleibt `cache.default` in der Config `null`;
 * faellt das Modul weg, gilt automatisch wieder der Zustand von heute.
 */
final class StatistikCacheManager extends CacheManager
{
    public const STORE = 'corefixes';

    public function __construct($app, private readonly string $ziel)
    {
        parent::__construct($app);
    }

    public function getDefaultDriver()
    {
        return self::STORE;
    }

    protected function getConfig($name)
    {
        return $name === self::STORE ? ['driver' => self::STORE] : parent::getConfig($name);
    }

    /** Wird von `CacheManager::resolve()` ueber den Treibernamen gefunden. */
    protected function createCorefixesDriver(array $config)
    {
        try {
            $innen = $this->store($this->ziel)->getStore();
            if (! $innen instanceof LockProvider) {
                throw new \InvalidArgumentException('Store "'.$this->ziel.'" kann keine Sperren');
            }

            return $this->repository(new StatistikAusnahmeStore($innen));
        } catch (\Throwable $e) {
            Log::warning('[CoreFixes] Anwendungs-Cache faellt auf "wie heute" (null) zurueck: '.$e->getMessage());

            return $this->repository(new NullStore());
        }
    }
}
