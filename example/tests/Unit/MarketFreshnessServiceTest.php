<?php

namespace Tests\Unit;

use App\Services\MarketFreshnessService;
use App\Services\MarketTradingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketFreshnessServiceTest extends TestCase
{
    use RefreshDatabase;

    private const JITA = MarketTradingService::REGION_THE_FORGE;

    private const AMARR = MarketTradingService::REGION_DOMAIN;

    private MarketFreshnessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        $this->service = new MarketFreshnessService;
    }

    public static function insertOrder(int $regionId, string $updatedAt, int $orderId = 1): void
    {
        DB::table('market_orders')->insert([
            'order_id' => $orderId,
            'region_id' => $regionId,
            'type_id' => 34,
            'is_buy_order' => false,
            'price' => 5.0,
            'volume_remain' => 100,
            'volume_total' => 100,
            'issued' => '2026-01-01 00:00:00',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    public static function insertCost(int $regionId, string $updatedAt, int $meLevel = 0): void
    {
        DB::table('manufacturing_costs')->insert([
            'product_type_id' => 587,
            'blueprint_type_id' => 691,
            'region_id' => $regionId,
            'me_level' => $meLevel,
            'product_name' => 'Rifter',
            'blueprint_name' => 'Rifter Blueprint',
            'input_cost' => 1.0,
            'output_value' => 2.0,
            'base_profit' => 1.0,
            'profit_margin' => 50.0,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    public static function insertGlobalPrice(string $fetchedAt): void
    {
        DB::table('market_prices')->insert([
            'type_id' => 34,
            'region_id' => MarketFreshnessService::GLOBAL_PRICES_REGION_ID,
            'average_price' => 5.0,
            'fetched_at' => $fetchedAt,
            'created_at' => $fetchedAt,
            'updated_at' => $fetchedAt,
        ]);
    }

    public function test_returns_every_trade_region_with_nulls_and_stale_when_empty(): void
    {
        $regions = $this->service->regions();

        $this->assertSame(array_keys(MarketTradingService::REGIONS), array_keys($regions));
        foreach ($regions as $regionId => $region) {
            $this->assertSame($regionId, $region['region_id']);
            $this->assertNull($region['orders_as_of']);
            $this->assertNull($region['costs_as_of']);
            $this->assertNull($region['global_as_of']);
            $this->assertTrue($region['stale']);
        }
    }

    public function test_uses_latest_timestamp_per_region(): void
    {
        self::insertOrder(self::JITA, '2026-10-21 09:00:00', 1);
        self::insertOrder(self::JITA, '2026-10-21 11:30:00', 2);
        self::insertOrder(self::AMARR, '2026-10-20 08:00:00', 3);
        self::insertCost(self::JITA, '2026-10-21 08:00:00', 0);
        self::insertCost(self::JITA, '2026-10-21 10:00:00', 1);
        self::insertGlobalPrice('2026-10-21 04:00:00');
        // Non-global region prices must not count as the global fetch.
        DB::table('market_prices')->insert(['type_id' => 34, 'region_id' => self::AMARR, 'fetched_at' => '2026-10-21 11:59:00']);

        $regions = $this->service->regions();

        $this->assertSame('2026-10-21T11:30:00Z', $regions[self::JITA]['orders_as_of']);
        $this->assertSame('2026-10-21T10:00:00Z', $regions[self::JITA]['costs_as_of']);
        $this->assertSame('2026-10-21T04:00:00Z', $regions[self::JITA]['global_as_of']);
        $this->assertFalse($regions[self::JITA]['stale']);

        $this->assertSame('2026-10-20T08:00:00Z', $regions[self::AMARR]['orders_as_of']);
        $this->assertNull($regions[self::AMARR]['costs_as_of']);
        $this->assertSame('2026-10-21T04:00:00Z', $regions[self::AMARR]['global_as_of']);
        $this->assertTrue($regions[self::AMARR]['stale']);
    }

    public function test_orders_as_of_is_the_start_of_the_last_completed_sweep(): void
    {
        // Raw writes from a sweep still in progress are newer than the snapshot.
        self::insertOrder(self::JITA, '2026-10-21 11:55:00', 1);
        self::insertOrder(self::AMARR, '2026-10-21 11:00:00', 2);
        foreach ([
            ['a', 'completed', '2026-10-21 11:15:00'],
            ['b', 'completed', '2026-10-21 11:30:00'],
            ['c', 'running', '2026-10-21 11:45:00'],
            ['d', 'failed', '2026-10-21 11:40:00'],
        ] as [$id, $status, $startedAt]) {
            DB::table('market_order_sweeps')->insert([
                'sweep_id' => $id, 'region_id' => self::JITA, 'status' => $status, 'started_at' => $startedAt,
                'created_at' => $startedAt, 'updated_at' => $startedAt,
            ]);
        }

        $regions = $this->service->regions();

        $this->assertSame('2026-10-21T11:30:00Z', $regions[self::JITA]['orders_as_of']);
        // Amarr has never been swept: last raw order write.
        $this->assertSame('2026-10-21T11:00:00Z', $regions[self::AMARR]['orders_as_of']);
    }

    public function test_stale_thresholds(): void
    {
        $fresh = fn (int $hours) => CarbonImmutable::now()->subHours($hours)->format('Y-m-d H:i:s');
        $ordersOk = $fresh(MarketFreshnessService::ORDERS_STALE_AFTER_HOURS);
        $costsOk = $fresh(MarketFreshnessService::COSTS_STALE_AFTER_HOURS);
        $globalOk = $fresh(MarketFreshnessService::GLOBAL_STALE_AFTER_HOURS);
        $tooOld = fn (int $hours) => CarbonImmutable::now()->subHours($hours)->subMinute()->format('Y-m-d H:i:s');

        // Exactly at each threshold is still fresh.
        $this->assertFalse($this->service->isStale($ordersOk, $costsOk, $globalOk));

        $this->assertTrue($this->service->isStale($tooOld(MarketFreshnessService::ORDERS_STALE_AFTER_HOURS), $costsOk, $globalOk));
        $this->assertTrue($this->service->isStale($ordersOk, $tooOld(MarketFreshnessService::COSTS_STALE_AFTER_HOURS), $globalOk));
        $this->assertTrue($this->service->isStale($ordersOk, $costsOk, $tooOld(MarketFreshnessService::GLOBAL_STALE_AFTER_HOURS)));

        $this->assertTrue($this->service->isStale(null, $costsOk, $globalOk));
        $this->assertTrue($this->service->isStale($ordersOk, null, $globalOk));
        $this->assertTrue($this->service->isStale($ordersOk, $costsOk, null));
    }

    public function test_results_are_cached_briefly(): void
    {
        $this->assertNull($this->service->regions()[self::JITA]['orders_as_of']);

        self::insertOrder(self::JITA, '2026-10-21 11:00:00');
        $this->assertNull($this->service->regions()[self::JITA]['orders_as_of']);

        $this->travel(MarketFreshnessService::CACHE_TTL_SECONDS + 1)->seconds();
        $this->assertSame('2026-10-21T11:00:00Z', $this->service->regions()[self::JITA]['orders_as_of']);
    }

    public function test_freshness_meta_prefers_regional_orders(): void
    {
        self::insertOrder(self::JITA, '2026-10-21 11:00:00');
        self::insertCost(self::JITA, '2026-10-21 10:00:00');
        self::insertGlobalPrice('2026-10-21 04:00:00');

        $this->assertSame([
            'prices_as_of' => '2026-10-21T11:00:00Z',
            'costs_as_of' => '2026-10-21T10:00:00Z',
            'source' => 'market_orders',
            'stale' => false,
        ], $this->service->freshnessMeta(self::JITA));

        $amarr = $this->service->freshnessMeta(self::AMARR);
        $this->assertSame('market_prices', $amarr['source']);
        $this->assertSame('2026-10-21T04:00:00Z', $amarr['prices_as_of']);
        $this->assertTrue($amarr['stale']);
    }

    public function test_freshness_meta_for_unknown_region(): void
    {
        $this->assertSame(
            ['prices_as_of' => null, 'costs_as_of' => null, 'source' => 'none', 'stale' => true],
            $this->service->freshnessMeta(99999999),
        );
    }
}
