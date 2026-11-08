<?php

namespace Tests\Unit;

use App\Models\MarketOrder;
use App\Models\MarketPrice;
use App\Services\AssetService;
use App\Services\EsiService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Material values on the assets page use MarketService (Jita), the same
 * price source as the calculators.
 */
class AssetServicePricingTest extends TestCase
{
    use RefreshDatabase;

    private function serviceFor(array $assets): AssetService
    {
        config(['assets.durable_snapshots' => false]);
        $service = Mockery::mock(AssetService::class, [Mockery::mock(EsiService::class), new MarketService(Mockery::mock(EsiService::class))])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getAssetsForUser')->with(1)->andReturn($assets);
        // Every type is a mineral (group 18)
        $service->shouldReceive('resolveGroupId')->andReturn(18);

        return $service;
    }

    private function asset(int $itemId, int $typeId, int $quantity): array
    {
        return [
            'item_id' => $itemId, 'type_id' => $typeId, 'type_name' => "Type {$typeId}", 'quantity' => $quantity,
            'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal',
        ];
    }

    public function test_materials_are_valued_with_the_canonical_jita_sell_price(): void
    {
        static $orderId = 8000000;

        // Type 34 has Jita sell orders; the 5% average comes from them, not sell_min
        foreach ([[5.0, 30], [6.0, 30], [9.0, 940]] as [$price, $volume]) {
            MarketOrder::create([
                'order_id' => ++$orderId, 'region_id' => MarketService::DEFAULT_REGION_ID, 'type_id' => 34,
                'is_buy_order' => false, 'price' => $price, 'volume_remain' => $volume, 'volume_total' => $volume,
                'issued' => now(),
            ]);
        }
        MarketPrice::create(['type_id' => 34, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 4.0]);
        // Type 35 has no orders and falls back to the Jita market_prices row
        MarketPrice::create(['type_id' => 35, 'region_id' => MarketService::DEFAULT_REGION_ID, 'average_price' => 12.0]);
        // Type 36 has no price anywhere

        $result = $this->serviceFor([
            $this->asset(1, 34, 1000),
            $this->asset(2, 35, 100),
            $this->asset(3, 36, 50),
        ])->getMaterialsForUser(1);

        $items = collect($result['groups'][0]['items'])->keyBy('type_id');

        // 5% of 1,000 units = 50: 30 @ 5 + 20 @ 6 → 5.40
        $this->assertEqualsWithDelta(5.4, $items[34]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(12.0, $items[35]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(0.0, $items[36]['unit_price'], 0.001);
        // 1,000 × 5.40 + 100 × 12 = 6,600
        $this->assertEqualsWithDelta(6600.0, $result['grand_total'], 0.001);
    }

    public function test_price_lookup_does_not_grow_with_the_number_of_types(): void
    {
        $queriesFor = function (int $typeCount): int {
            $assets = [];
            for ($i = 1; $i <= $typeCount; $i++) {
                $assets[] = $this->asset($i, 1000 + $i, 10);
            }
            $service = $this->serviceFor($assets);
            // Both runs start cold: the first run caches the region's
            // (absent) aggregate snapshot, which would save the second a query.
            \Illuminate\Support\Facades\Cache::forget(\App\Services\MarketAggregateService::SNAPSHOT_CACHE_PREFIX.\App\Services\MarketService::DEFAULT_REGION_ID);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->getMaterialsForUser(1);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame($queriesFor(2), $queriesFor(40));
    }
}
