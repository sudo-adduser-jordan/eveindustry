<?php

namespace Tests\Unit;

use App\Services\EsiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiServiceSystemCacheTest extends TestCase
{
    private EsiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->service = new EsiService;
    }

    public function test_search_types_solar_system_uses_30_day_cache(): void
    {
        $searchResult = ['solar_system' => [30000142]];

        Http::fake([
            '*/search/*' => Http::response($searchResult, 200),
        ]);

        $result = $this->service->searchTypes('Jita', 'solar_system');
        $this->assertEquals($searchResult, $result);

        $cacheKey = 'esi.search.'.md5('Jita').'.solar_system.0';
        $this->assertEquals($searchResult, Cache::get($cacheKey));
    }

    public function test_search_types_inventory_type_does_not_use_30_day_cache(): void
    {
        $searchResult = ['inventory_type' => [34]];

        Http::fake([
            '*/search/*' => Http::response($searchResult, 200),
        ]);

        $result = $this->service->searchTypes('Tritanium', 'inventory_type');
        $this->assertEquals($searchResult, $result);

        $cacheKey = 'esi.search.'.md5('Tritanium').'.inventory_type.0';
        $this->assertEquals($searchResult, Cache::get($cacheKey));
    }

    public function test_search_types_solar_system_returns_cached_value_without_http_call(): void
    {
        $searchResult = ['solar_system' => [30000142]];
        $cacheKey = 'esi.search.'.md5('Jita').'.solar_system.0';
        Cache::put($cacheKey, $searchResult, EsiService::SDE_CACHE_TTL);

        Http::fake([
            '*/search/*' => Http::response(null, 500),
        ]);

        $result = $this->service->searchTypes('Jita', 'solar_system');
        $this->assertEquals($searchResult, $result);

        Http::assertNothingSent();
    }

    public function test_resolve_universe_ids_uses_30_day_cache(): void
    {
        $resolved = ['systems' => [['id' => 30000142, 'name' => 'Jita']]];

        Http::fake([
            '*/universe/ids/*' => Http::response($resolved, 200),
        ]);

        $result = $this->service->resolveUniverseIds(['Jita']);
        $this->assertEquals($resolved, $result);

        $cacheKey = 'esi.universe_ids.'.md5('Jita');
        $this->assertEquals($resolved, Cache::get($cacheKey));
    }

    public function test_resolve_universe_ids_returns_cached_value_without_http_call(): void
    {
        $resolved = ['systems' => [['id' => 30000142, 'name' => 'Jita']]];
        $cacheKey = 'esi.universe_ids.'.md5('Jita');
        Cache::put($cacheKey, $resolved, EsiService::SDE_CACHE_TTL);

        Http::fake([
            '*/universe/ids/*' => Http::response(null, 500),
        ]);

        $result = $this->service->resolveUniverseIds(['Jita']);
        $this->assertEquals($resolved, $result);

        Http::assertNothingSent();
    }

    public function test_get_system_uses_30_day_cache(): void
    {
        $systemData = ['system_id' => 30000142, 'name' => 'Jita', 'security_status' => 0.9];

        Http::fake([
            '*/universe/systems/*' => Http::response($systemData, 200),
        ]);

        $result = $this->service->getSystem(30000142);
        $this->assertEquals($systemData, $result);
        $this->assertEquals($systemData, Cache::get('esi.system.30000142'));
    }

    public function test_get_system_returns_cached_value_without_http_call(): void
    {
        $systemData = ['system_id' => 30000142, 'name' => 'Jita', 'security_status' => 0.9];
        Cache::put('esi.system.30000142', $systemData, EsiService::SDE_CACHE_TTL);

        Http::fake([
            '*/universe/systems/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getSystem(30000142);
        $this->assertEquals($systemData, $result);

        Http::assertNothingSent();
    }

    public function test_sde_cache_ttl_is_30_days(): void
    {
        $this->assertEquals(2592000, EsiService::SDE_CACHE_TTL);
    }
}
