<?php

namespace Tests\Unit;

use App\Jobs\FetchRegionHistoryChunk;
use App\Models\MarketHistory;
use App\Services\EsiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchRegionHistoryChunkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    private function historyEntry(string $date, float $avg = 100.0): array
    {
        return [
            'date' => $date,
            'average' => $avg,
            'highest' => $avg * 1.05,
            'lowest' => $avg * 0.95,
            'volume' => 1000,
            'order_count' => 10,
        ];
    }

    public function test_job_is_batchable(): void
    {
        $job = new FetchRegionHistoryChunk(10000002, [34]);
        $this->assertContains(\Illuminate\Bus\Batchable::class, class_uses_recursive($job));
    }

    public function test_timeout_is_reduced_from_600(): void
    {
        $job = new FetchRegionHistoryChunk(10000002, [34]);
        $this->assertLessThanOrEqual(120, $job->timeout, 'Timeout should be ≤120 s now that requests are concurrent');
    }

    public function test_job_inserts_history_for_multiple_types(): void
    {
        $regionId = 10000002;
        $date = Carbon::now()->subDays(1)->toDateString();

        Http::fake([
            '*/markets/*/history/*' => Http::sequence()
                ->push([$this->historyEntry($date, 100.0)], 200)
                ->push([$this->historyEntry($date, 200.0)], 200),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34, 35]);
        $job->handle(new EsiService);

        $this->assertEquals(2, MarketHistory::count());
        $this->assertDatabaseHas('market_history', ['region_id' => $regionId, 'type_id' => 34, 'average' => 100.0]);
        $this->assertDatabaseHas('market_history', ['region_id' => $regionId, 'type_id' => 35, 'average' => 200.0]);
    }

    public function test_job_skips_entries_older_than_30_days(): void
    {
        $regionId = 10000002;
        $oldDate = Carbon::now()->subDays(35)->toDateString();
        $newDate = Carbon::now()->subDays(1)->toDateString();

        Http::fake([
            '*/markets/*/history/*' => Http::response([
                $this->historyEntry($oldDate, 50.0),
                $this->historyEntry($newDate, 99.0),
            ], 200),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34]);
        $job->handle(new EsiService);

        // Only the recent entry should be stored
        $this->assertEquals(1, MarketHistory::count());
        $this->assertDatabaseHas('market_history', ['average' => 99.0]);
        $this->assertDatabaseMissing('market_history', ['average' => 50.0]);
    }

    public function test_job_handles_304_not_modified_via_etag(): void
    {
        $regionId = 10000002;

        // Seed an ETag in the cache, simulating a prior successful fetch
        Cache::put("market.history.etag.{$regionId}.34", '"abc123"', now()->addHour());

        Http::fake([
            '*/markets/*/history/*' => Http::response(null, 304),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34]);
        $job->handle(new EsiService);

        // No DB rows needed — data was unchanged
        $this->assertEquals(0, MarketHistory::count());
    }

    public function test_job_stores_etag_from_response(): void
    {
        $regionId = 10000002;
        $date = Carbon::now()->subDays(1)->toDateString();

        Http::fake([
            '*/markets/*/history/*' => Http::response(
                [$this->historyEntry($date)],
                200,
                ['ETag' => '"etag-value-xyz"']
            ),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34]);
        $job->handle(new EsiService);

        $this->assertEquals('"etag-value-xyz"', Cache::get("market.history.etag.{$regionId}.34"));
    }

    public function test_job_sends_if_none_match_header_when_etag_cached(): void
    {
        $regionId = 10000002;
        Cache::put("market.history.etag.{$regionId}.34", '"cached-etag"', now()->addHour());

        Http::fake([
            '*/markets/*/history/*' => Http::response(null, 304),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34]);
        $job->handle(new EsiService);

        Http::assertSent(function ($request) {
            return $request->hasHeader('If-None-Match', '"cached-etag"');
        });
    }

    public function test_job_does_not_send_if_none_match_without_cached_etag(): void
    {
        $regionId = 10000002;
        $date = Carbon::now()->subDays(1)->toDateString();

        Http::fake([
            '*/markets/*/history/*' => Http::response([$this->historyEntry($date)], 200),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34]);
        $job->handle(new EsiService);

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('If-None-Match');
        });
    }

    public function test_job_upserts_existing_records(): void
    {
        $regionId = 10000002;
        $date = Carbon::now()->subDays(1)->toDateString();

        \Illuminate\Support\Facades\DB::table('market_history')->insert([
            'region_id' => $regionId,
            'type_id' => 34,
            'date' => $date,
            'average' => 50.0,
            'highest' => 55.0,
            'lowest' => 45.0,
            'volume' => 500,
            'order_count' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            '*/markets/*/history/*' => Http::response([$this->historyEntry($date, 99.0)], 200),
        ]);

        $job = new FetchRegionHistoryChunk($regionId, [34]);
        $job->handle(new EsiService);

        $this->assertEquals(1, MarketHistory::count());
        $this->assertDatabaseHas('market_history', ['average' => 99.0]);
    }

    public function test_job_handles_http_error_gracefully(): void
    {
        Http::fake([
            '*/markets/*/history/*' => Http::response(null, 500),
        ]);

        $job = new FetchRegionHistoryChunk(10000002, [34]);
        $job->handle(new EsiService);

        $this->assertEquals(0, MarketHistory::count());
    }

    public function test_job_handles_empty_response_gracefully(): void
    {
        Http::fake([
            '*/markets/*/history/*' => Http::response([], 200),
        ]);

        $job = new FetchRegionHistoryChunk(10000002, [34]);
        $job->handle(new EsiService);

        $this->assertEquals(0, MarketHistory::count());
    }
}
