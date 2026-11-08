<?php

namespace Tests\Unit;

use App\Services\EsiService;
use Tests\TestCase;

class MarketCacheTtlTest extends TestCase
{
    public function test_region_orders_cache_ttl_is_two_hours(): void
    {
        $this->assertEquals(7200, EsiService::REGION_ORDERS_CACHE_TTL);
    }
}
