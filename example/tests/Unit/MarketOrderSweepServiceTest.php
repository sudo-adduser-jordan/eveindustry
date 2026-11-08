<?php

namespace Tests\Unit;

use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\MarketAggregate;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Models\MarketRegionState;
use App\Services\Esi\RegionOrdersPage;
use App\Services\MarketAggregateService;
use App\Services\MarketOrderSweepService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketOrderSweepServiceTest extends TestCase
{
    use RefreshDatabase;

    private const JITA = 10000002;

    private const AMARR = 10000043;

    private MarketOrderSweepService $sweeps;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Queue::fake([PrecomputeManufacturingCostsChunk::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $this->sweeps = app(MarketOrderSweepService::class);
    }

    private function esiOrder(int $orderId, int $typeId, float $price, bool $buy = false, int $volume = 10): array
    {
        return [
            'order_id' => $orderId, 'type_id' => $typeId, 'is_buy_order' => $buy, 'price' => $price,
            'volume_remain' => $volume, 'volume_total' => $volume, 'min_volume' => 1,
            'location_id' => 60003760, 'duration' => 90, 'range' => 'region', 'issued' => '2026-10-01T00:00:00Z',
        ];
    }

    private function page(array $orders, ?string $etag = '"e1"', int $pages = 1): RegionOrdersPage
    {
        return new RegionOrdersPage(RegionOrdersPage::OK, orders: $orders, pages: $pages, etag: $etag, httpStatus: 200);
    }

    private function seedOrder(int $orderId, int $regionId, string $updatedAt, string $batch = 'old'): void
    {
        MarketOrder::insert([
            'order_id' => $orderId, 'region_id' => $regionId, 'type_id' => 34, 'is_buy_order' => false,
            'price' => 5.0, 'volume_remain' => 10, 'volume_total' => 10, 'min_volume' => 1,
            'issued' => '2026-10-01 00:00:00', 'batch_id' => $batch,
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }

    public function test_start_creates_a_running_sweep(): void
    {
        $sweep = $this->sweeps->start(self::JITA);

        $this->assertNotNull($sweep);
        $this->assertSame(MarketOrderSweep::STATUS_RUNNING, $sweep->status);
        $this->assertSame(self::JITA, $sweep->region_id);
        $this->assertSame('2026-10-22 12:00:00', $sweep->started_at->toDateTimeString());
    }

    public function test_start_refuses_a_second_sweep_while_one_is_running_in_the_region(): void
    {
        $this->assertNotNull($this->sweeps->start(self::JITA));

        $this->assertNull($this->sweeps->start(self::JITA));
        // Other regions are independent.
        $this->assertNotNull($this->sweeps->start(self::AMARR));
    }

    public function test_start_abandons_a_sweep_that_has_run_too_long(): void
    {
        $old = $this->sweeps->start(self::JITA);
        $this->travel(MarketOrderSweepService::RUNNING_TIMEOUT_MINUTES + 1)->minutes();

        $new = $this->sweeps->start(self::JITA);

        $this->assertNotNull($new);
        $this->assertSame(MarketOrderSweep::STATUS_ABANDONED, $old->fresh()->status);
    }

    public function test_store_page_upserts_orders_counts_them_and_caches_the_etag_with_order_ids(): void
    {
        $sweep = $this->sweeps->start(self::JITA);

        $stored = $this->sweeps->storePage($sweep->sweep_id, self::JITA, 3, $this->page([
            $this->esiOrder(1, 34, 5.0),
            $this->esiOrder(2, 35, 4.0, true),
        ], '"abc"', 7));

        $this->assertSame(2, $stored);
        $this->assertSame(2, MarketOrder::where('batch_id', $sweep->sweep_id)->count());
        $this->assertSame(['etag' => '"abc"', 'order_ids' => [1, 2], 'pages' => 7], $this->sweeps->cachedPage(self::JITA, 3));

        $sweep->refresh();
        $this->assertSame(1, $sweep->pages_fetched);
        $this->assertSame(2, $sweep->orders_seen);
    }

    public function test_unchanged_page_marks_its_cached_orders_as_seen_by_this_sweep(): void
    {
        $this->seedOrder(1, self::JITA, '2026-10-22 11:00:00');
        $this->seedOrder(2, self::JITA, '2026-10-22 11:00:00');
        $sweep = $this->sweeps->start(self::JITA);

        $this->sweeps->markPageUnchanged($sweep->sweep_id, self::JITA, 2, ['etag' => '"e"', 'order_ids' => [1, 2], 'pages' => 2]);

        $this->assertSame(2, MarketOrder::where('batch_id', $sweep->sweep_id)->where('updated_at', '>=', '2026-10-22 12:00:00')->count());
        $sweep->refresh();
        $this->assertSame(1, $sweep->pages_fetched);
        $this->assertSame(1, $sweep->pages_not_modified);
        $this->assertSame(2, $sweep->orders_seen);
    }

    public function test_complete_deletes_only_orders_the_sweep_did_not_see_and_builds_aggregates(): void
    {
        // Seen by an earlier sweep but gone from ESI now.
        $this->seedOrder(100, self::JITA, '2026-10-22 11:30:00');
        // Another region is untouched.
        $this->seedOrder(200, self::AMARR, '2026-10-22 11:30:00');

        $sweep = $this->sweeps->start(self::JITA);
        $this->travel(5)->minutes();
        $this->sweeps->storePage($sweep->sweep_id, self::JITA, 1, $this->page([
            $this->esiOrder(1, 34, 5.0),
            $this->esiOrder(2, 34, 4.0, true),
        ]));
        $this->travel(2)->minutes();

        $this->sweeps->complete($sweep->sweep_id);

        $this->assertEqualsCanonicalizing([1, 2, 200], MarketOrder::pluck('order_id')->all());

        $sweep->refresh();
        $this->assertSame(MarketOrderSweep::STATUS_COMPLETED, $sweep->status);
        $this->assertSame(420, $sweep->duration_seconds);
        $this->assertSame(1, $sweep->orders_deleted);
        $this->assertSame(1, $sweep->types_aggregated);

        $agg = MarketAggregate::where('region_id', self::JITA)->where('type_id', 34)->first();
        $this->assertSame(5.0, $agg->best_ask);
        $this->assertSame(4.0, $agg->best_bid);
        $this->assertSame($sweep->sweep_id, $agg->sweep_id);

        $this->assertSame($sweep->sweep_id, app(MarketAggregateService::class)->currentSnapshot(self::JITA)['sweep_id']);
        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, 1);
    }

    public function test_completion_preserves_old_generation_and_atomically_advances_active_pointer(): void
    {
        $first = $this->sweeps->start(self::JITA);
        $this->sweeps->storePage($first->sweep_id, self::JITA, 1, $this->page([$this->esiOrder(1, 34, 5.0)]));
        $this->sweeps->complete($first->sweep_id);

        $second = $this->sweeps->start(self::JITA);
        $this->sweeps->storePage($second->sweep_id, self::JITA, 1, $this->page([$this->esiOrder(1, 34, 7.0)]));
        $completed = $this->sweeps->complete($second->sweep_id);

        $state = MarketRegionState::find(self::JITA);
        $this->assertSame(MarketOrderSweep::STATUS_COMPLETED, $completed->status);
        $this->assertSame($second->sweep_id, $state->active_sweep_id);
        $this->assertSame($second->generation, $state->active_generation);
        $this->assertSame(2, MarketAggregate::where('region_id', self::JITA)->count());
        $this->assertSame(5.0, MarketAggregate::where('sweep_id', $first->sweep_id)->value('best_ask'));
        $this->assertSame(7.0, MarketAggregate::where('sweep_id', $second->sweep_id)->value('best_ask'));
        $this->assertSame([34 => 7.0], app(MarketAggregateService::class)->sellPrices([34], self::JITA));
    }

    public function test_fail_keeps_every_order_and_records_the_reason(): void
    {
        $this->seedOrder(100, self::JITA, '2026-10-22 11:30:00');
        $sweep = $this->sweeps->start(self::JITA);

        $this->sweeps->fail($sweep->sweep_id, 'page 4 failed');

        $this->assertSame(1, MarketOrder::count());
        $sweep->refresh();
        $this->assertSame(MarketOrderSweep::STATUS_FAILED, $sweep->status);
        $this->assertSame('page 4 failed', $sweep->error);
        $this->assertNull(app(MarketAggregateService::class)->currentSnapshot(self::JITA));
        Queue::assertNotPushed(PrecomputeManufacturingCostsChunk::class);
    }

    public function test_complete_ignores_a_sweep_that_is_no_longer_running(): void
    {
        $this->seedOrder(100, self::JITA, '2026-10-22 11:30:00');
        $sweep = $this->sweeps->start(self::JITA);
        $sweep->update(['status' => MarketOrderSweep::STATUS_ABANDONED]);

        $this->sweeps->complete($sweep->sweep_id);

        $this->assertSame(1, MarketOrder::count());
        $this->assertSame(MarketOrderSweep::STATUS_ABANDONED, $sweep->fresh()->status);
    }

    public function test_precompute_is_triggered_at_most_once_per_interval_per_region(): void
    {
        foreach ([0, 15, MarketOrderSweepService::PRECOMPUTE_MIN_INTERVAL_MINUTES] as $minutes) {
            $this->travel($minutes)->minutes();
            $sweep = $this->sweeps->start(self::JITA);
            $this->sweeps->complete($sweep->sweep_id);
        }

        // 0 → triggered, +15 → throttled, +30 more → triggered again.
        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, 2);
    }

    public function test_jita_cadence_is_slow_until_a_sweep_is_measured_then_fast_while_under_ten_minutes(): void
    {
        $this->assertSame(30, $this->sweeps->cadenceMinutes(self::JITA));

        $sweep = $this->sweeps->start(self::JITA);
        $this->travel(9)->minutes();
        $this->sweeps->complete($sweep->sweep_id);
        $this->assertSame(15, $this->sweeps->cadenceMinutes(self::JITA));

        $this->travel(10)->minutes();
        $sweep = $this->sweeps->start(self::JITA);
        $this->travel(MarketOrderSweepService::FAST_CADENCE_MAX_SECONDS)->seconds();
        $this->sweeps->complete($sweep->sweep_id);
        $this->assertSame(30, $this->sweeps->cadenceMinutes(self::JITA));
    }

    public function test_is_due_runs_every_tick_at_fast_cadence_and_only_on_the_half_hour_at_slow_cadence(): void
    {
        $at = fn (string $time) => CarbonImmutable::parse("2026-10-22 {$time}", 'UTC');

        // No measured sweep: 30-minute cadence.
        $this->assertTrue($this->sweeps->isDue(self::JITA, $at('13:00')));
        $this->assertFalse($this->sweeps->isDue(self::JITA, $at('13:15')));
        $this->assertTrue($this->sweeps->isDue(self::JITA, $at('13:30')));
        $this->assertFalse($this->sweeps->isDue(self::JITA, $at('13:45')));

        $sweep = $this->sweeps->start(self::JITA);
        $this->travel(3)->minutes();
        $this->sweeps->complete($sweep->sweep_id);

        foreach (['13:00', '13:15', '13:30', '13:45'] as $time) {
            $this->assertTrue($this->sweeps->isDue(self::JITA, $at($time)), $time);
        }
    }

    public function test_cached_page_ignores_entries_without_order_ids(): void
    {
        Cache::put(MarketOrderSweepService::PAGE_CACHE_PREFIX.self::JITA.'.1', ['etag' => '"x"'], 60);

        $this->assertNull($this->sweeps->cachedPage(self::JITA, 1));
    }
}
