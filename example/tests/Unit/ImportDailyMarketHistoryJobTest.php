<?php

namespace Tests\Unit;

use App\Jobs\ImportDailyMarketHistory;
use App\Models\MarketHistory;
use App\Services\MarketTradingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportDailyMarketHistoryJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    private function makeBz2Csv(array $rows, array $headers): string
    {
        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_mh_');
        file_put_contents($tmpFile, implode("\n", $lines));
        $compressed = shell_exec('bzip2 -c ' . escapeshellarg($tmpFile));
        unlink($tmpFile);
        $this->assertNotNull($compressed, 'bzip2 command failed to compress test CSV data');
        return (string) $compressed;
    }

    public function test_job_is_queueable(): void
    {
        $job = new ImportDailyMarketHistory(Carbon::today());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    public function test_job_upsert_chunk_size_constant(): void
    {
        $this->assertEquals(500, ImportDailyMarketHistory::UPSERT_CHUNK_SIZE);
    }

    public function test_job_skips_insert_on_http_failure(): void
    {
        Http::fake([
            'data.everef.net/*' => Http::response(null, 503),
        ]);

        $job = new ImportDailyMarketHistory(Carbon::parse('2026-02-22'));
        $job->handle();

        $this->assertEquals(0, MarketHistory::count());
    }

    public function test_job_inserts_rows_for_known_regions(): void
    {
        $regionId = MarketTradingService::REGION_THE_FORGE;
        $date     = '2026-02-22';

        $csvContent = $this->makeBz2Csv(
            [
                [$date, $regionId, 34, 100.50, 105.00, 98.00, 5000, 20],
                [$date, $regionId, 35, 200.00, 210.00, 195.00, 2000, 10],
            ],
            ['date', 'region_id', 'type_id', 'average', 'highest', 'lowest', 'volume', 'order_count']
        );

        Http::fake([
            'data.everef.net/*' => Http::response($csvContent, 200),
        ]);

        $job = new ImportDailyMarketHistory(Carbon::parse($date));
        $job->handle();

        $this->assertEquals(2, MarketHistory::count());

        $this->assertDatabaseHas('market_history', [
            'region_id'   => $regionId,
            'type_id'     => 34,
            'date'        => $date,
            'average'     => 100.50,
            'volume'      => 5000,
            'order_count' => 20,
        ]);
    }

    public function test_job_skips_rows_for_unknown_regions(): void
    {
        $unknownRegionId = 99999999;
        $date            = '2026-02-22';

        $csvContent = $this->makeBz2Csv(
            [
                [$date, $unknownRegionId, 34, 100.00, 105.00, 95.00, 100, 5],
            ],
            ['date', 'region_id', 'type_id', 'average', 'highest', 'lowest', 'volume', 'order_count']
        );

        Http::fake([
            'data.everef.net/*' => Http::response($csvContent, 200),
        ]);

        $job = new ImportDailyMarketHistory(Carbon::parse($date));
        $job->handle();

        $this->assertEquals(0, MarketHistory::count());
    }

    public function test_job_upserts_existing_records(): void
    {
        $regionId = MarketTradingService::REGION_THE_FORGE;
        $date     = '2026-02-22';

        // Insert directly to match the date format the job uses (no Eloquent casting)
        \Illuminate\Support\Facades\DB::table('market_history')->insert([
            'region_id'   => $regionId,
            'type_id'     => 34,
            'date'        => $date,
            'average'     => 50.00,
            'highest'     => 55.00,
            'lowest'      => 45.00,
            'volume'      => 1000,
            'order_count' => 5,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $csvContent = $this->makeBz2Csv(
            [[$date, $regionId, 34, 99.99, 105.00, 95.00, 9999, 50]],
            ['date', 'region_id', 'type_id', 'average', 'highest', 'lowest', 'volume', 'order_count']
        );

        Http::fake([
            'data.everef.net/*' => Http::response($csvContent, 200),
        ]);

        $job = new ImportDailyMarketHistory(Carbon::parse($date));
        $job->handle();

        // Still only one record (upserted)
        $this->assertEquals(1, MarketHistory::count());

        $this->assertDatabaseHas('market_history', [
            'region_id'   => $regionId,
            'type_id'     => 34,
            'date'        => $date,
            'average'     => 99.99,
            'volume'      => 9999,
            'order_count' => 50,
        ]);
    }

    public function test_job_builds_correct_url_from_date(): void
    {
        Http::fake([
            'data.everef.net/market-history/2026/market-history-2026-02-22.csv.bz2' => Http::response(null, 404),
        ]);

        $job = new ImportDailyMarketHistory(Carbon::parse('2026-02-22'));
        $job->handle();

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'data.everef.net/market-history/2026/market-history-2026-02-22.csv.bz2');
        });
    }
}
