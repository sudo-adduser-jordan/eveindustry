<?php

namespace Tests\Unit;

use App\Models\MarketAggregate;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Models\MarketRegionState;
use App\Services\EsiService;
use App\Services\MarketAggregateService;
use App\Services\MarketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketAggregateServiceTest extends TestCase
{
    use RefreshDatabase;

    private const JITA = 10000002;

    private MarketAggregateService $aggregates;

    private int $nextOrderId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $this->aggregates = app(MarketAggregateService::class);
    }

    private function order(int $typeId, float $price, int $volume, bool $buy = false, string $issued = '2026-10-01 00:00:00', int $regionId = self::JITA): void
    {
        MarketOrder::insert([
            'order_id' => $this->nextOrderId++, 'region_id' => $regionId, 'type_id' => $typeId,
            'is_buy_order' => $buy, 'price' => $price, 'volume_remain' => $volume, 'volume_total' => max($volume, 1),
            'min_volume' => 1, 'issued' => $issued, 'batch_id' => 's',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function completeSweep(string $sweepId, int $regionId = self::JITA): void
    {
        $state = MarketRegionState::firstOrCreate(['region_id' => $regionId]);
        $generation = $state->latest_generation + 1;

        MarketOrderSweep::create([
            'sweep_id' => $sweepId, 'region_id' => $regionId, 'generation' => $generation,
            'status' => MarketOrderSweep::STATUS_COMPLETED,
            'started_at' => now(), 'finished_at' => now(), 'duration_seconds' => 60,
        ]);

        $state->update([
            'latest_generation' => $generation,
            'latest_sweep_id' => $sweepId,
            'latest_started_at' => now(),
        ]);
        $this->aggregates->publishSnapshot($regionId, $sweepId, now());
    }

    public function test_rebuild_summarises_each_type_and_matches_the_raw_order_price(): void
    {
        // Type 34: sell side spread over several orders, two bids.
        $this->order(34, 5.00, 100);
        $this->order(34, 5.50, 400);
        $this->order(34, 7.00, 1500);
        $this->order(34, 4.00, 300, true);
        $this->order(34, 4.20, 50, true);
        // Ignored: fulfilled, and older than 90 days.
        $this->order(34, 1.00, 0);
        $this->order(34, 0.50, 1000, false, '2026-06-01 00:00:00');
        // Type 35: buy orders only.
        $this->order(35, 9.00, 10, true);

        $rawPrice = (new MarketService(new EsiService))->getSellPrices([34], self::JITA)[34];

        $types = $this->aggregates->rebuild(self::JITA, 'sweep-1', now());

        $this->assertSame(2, $types);

        $tritanium = MarketAggregate::where('type_id', 34)->first();
        $this->assertSame(5.0, $tritanium->best_ask);
        $this->assertSame(4.2, $tritanium->best_bid);
        $this->assertSame(2000, $tritanium->sell_volume);
        $this->assertSame(350, $tritanium->buy_volume);
        $this->assertSame(3, $tritanium->sell_orders);
        $this->assertSame(2, $tritanium->buy_orders);
        $this->assertSame($rawPrice, $tritanium->sell_price_p5);
        $this->assertSame('sweep-1', $tritanium->sweep_id);

        $pyerite = MarketAggregate::where('type_id', 35)->first();
        $this->assertNull($pyerite->best_ask);
        $this->assertNull($pyerite->sell_price_p5);
        $this->assertSame(9.0, $pyerite->best_bid);
    }

    public function test_rebuild_keeps_previous_generation_and_omits_types_that_left_current_market(): void
    {
        $this->order(34, 5.0, 10);
        $this->order(35, 6.0, 10);
        $this->aggregates->rebuild(self::JITA, 'sweep-1', now());

        DB::table('market_orders')->where('type_id', 35)->delete();
        $this->aggregates->rebuild(self::JITA, 'sweep-2', now());

        $this->assertSame([34, 35, 34], MarketAggregate::orderBy('sweep_id')->orderBy('type_id')->pluck('type_id')->all());
        $this->assertSame([34], MarketAggregate::where('sweep_id', 'sweep-2')->pluck('type_id')->all());
    }

    public function test_sell_prices_is_null_until_a_sweep_has_completed(): void
    {
        $this->order(34, 5.0, 10);
        $this->aggregates->rebuild(self::JITA, 'sweep-1', now());

        $this->assertNull($this->aggregates->sellPrices([34], self::JITA));
    }

    public function test_sell_prices_reads_the_current_snapshot_and_zero_for_absent_types(): void
    {
        $this->order(34, 5.0, 10);
        $this->aggregates->rebuild(self::JITA, 'sweep-1', now());
        $this->completeSweep('sweep-1');

        $this->assertSame([34 => 5.0, 99 => 0.0], $this->aggregates->sellPrices([34, 99], self::JITA));
    }

    public function test_prices_are_cached_per_snapshot_and_a_new_snapshot_is_read_fresh(): void
    {
        $this->order(34, 5.0, 10);
        $this->aggregates->rebuild(self::JITA, 'sweep-1', now());
        $this->completeSweep('sweep-1');
        $this->assertSame([34 => 5.0], $this->aggregates->sellPrices([34], self::JITA));

        // The row changes underneath, but the snapshot has not: cached value.
        DB::table('market_aggregates')->where('sweep_id', 'sweep-1')->update(['sell_price_p5' => 6.0]);
        $this->assertSame([34 => 5.0], $this->aggregates->sellPrices([34], self::JITA));

        // A new sweep publishes a new snapshot: new cache keys.
        $this->travel(15)->minutes();
        $this->aggregates->rebuild(self::JITA, 'sweep-2', now());
        DB::table('market_aggregates')->where('sweep_id', 'sweep-2')->update(['sell_price_p5' => 6.0]);
        $this->completeSweep('sweep-2');
        $this->assertSame([34 => 6.0], $this->aggregates->sellPrices([34], self::JITA));
    }

    public function test_cache_miss_reads_only_the_active_generation_while_a_new_one_is_building(): void
    {
        $this->order(34, 5.0, 10);
        $this->aggregates->rebuild(self::JITA, 'sweep-1', now());
        $this->completeSweep('sweep-1');

        DB::table('market_orders')->where('type_id', 34)->update(['price' => 7.0]);
        $this->aggregates->rebuild(self::JITA, 'sweep-2', now());
        Cache::forget(MarketAggregateService::SNAPSHOT_CACHE_PREFIX.self::JITA);
        Cache::forget(MarketAggregateService::PRICE_CACHE_PREFIX.self::JITA.'.sweep-1.34');

        $this->assertSame([34 => 5.0], $this->aggregates->sellPrices([34], self::JITA));
        $this->assertSame(2, MarketAggregate::where('region_id', self::JITA)->count());
    }

    public function test_stale_completed_generation_cannot_move_the_active_pointer_backwards(): void
    {
        $this->completeSweep('sweep-1');
        $this->travel(15)->minutes();
        $this->completeSweep('sweep-2');

        $this->aggregates->publishSnapshot(self::JITA, 'sweep-1', now()->subMinutes(15));

        $this->assertSame('sweep-2', $this->aggregates->currentSnapshot(self::JITA)['sweep_id']);
        $this->assertSame('sweep-2', MarketRegionState::find(self::JITA)->active_sweep_id);
    }

    public function test_current_snapshot_comes_from_the_latest_completed_sweep(): void
    {
        $this->completeSweep('sweep-1');
        $this->travel(15)->minutes();
        $this->completeSweep('sweep-2');
        MarketOrderSweep::create([
            'sweep_id' => 'sweep-3', 'region_id' => self::JITA, 'status' => MarketOrderSweep::STATUS_FAILED,
            'started_at' => now()->addMinutes(15), 'finished_at' => now()->addMinutes(16),
        ]);

        $this->assertSame(
            ['sweep_id' => 'sweep-2', 'snapshot_at' => '2026-10-22T12:15:00Z'],
            $this->aggregates->currentSnapshot(self::JITA),
        );
    }
}
