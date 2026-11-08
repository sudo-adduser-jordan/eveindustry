<?php

namespace Tests\Feature;

use App\Jobs\CompleteMarketOrderSweep;
use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\MarketOrderSweep;
use App\Services\MarketOrderSweepService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Region order sweeps are scheduled (Jita gated every 15 minutes, other hubs
 * hourly), measurable with market:sweep-status, and completed by
 * CompleteMarketOrderSweep.
 */
class MarketOrderSweepScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    /**
     * @return list<Event>
     */
    private function sweepEvents(): array
    {
        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'market:fetch-region-orders'),
        ));
    }

    private function eventFor(string $needle): Event
    {
        foreach ($this->sweepEvents() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        $this->fail("No market:fetch-region-orders event with {$needle}");
    }

    public function test_jita_and_the_other_hubs_are_scheduled_separately(): void
    {
        $this->assertCount(2, $this->sweepEvents());

        $jita = $this->eventFor('--region=10000002');
        $this->assertSame('*/15 * * * *', $jita->expression);
        $this->assertTrue($jita->withoutOverlapping);
        $this->assertTrue($jita->onOneServer);

        $hubs = $this->eventFor('--exclude=10000002');
        $this->assertSame('0 * * * *', $hubs->expression);
        $this->assertTrue($hubs->withoutOverlapping);
        $this->assertTrue($hubs->onOneServer);
    }

    public function test_jita_entry_skips_the_quarter_hours_until_a_fast_sweep_is_measured(): void
    {
        $jita = $this->eventFor('--region=10000002');

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:15:00', 'UTC'));
        $this->assertFalse($jita->filtersPass($this->app));

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:30:00', 'UTC'));
        $this->assertTrue($jita->filtersPass($this->app));

        MarketOrderSweep::create([
            'sweep_id' => 's1', 'region_id' => 10000002, 'status' => MarketOrderSweep::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(1), 'duration_seconds' => 240,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:45:00', 'UTC'));
        $this->assertTrue($jita->filtersPass($this->app));
    }

    public function test_sweep_status_reports_the_latest_sweep_its_duration_and_the_jita_cadence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        MarketOrderSweep::create([
            'sweep_id' => 's1', 'region_id' => 10000002, 'status' => MarketOrderSweep::STATUS_COMPLETED,
            'started_at' => '2026-10-22 11:45:00', 'finished_at' => '2026-10-22 11:52:00', 'duration_seconds' => 420,
            'pages_total' => 380, 'pages_fetched' => 380, 'pages_not_modified' => 120, 'orders_seen' => 375000,
        ]);

        $this->assertSame(0, Artisan::call('market:sweep-status'));
        $output = Artisan::output();

        $this->assertStringContainsString('420s', $output);
        $this->assertStringContainsString('380/380 (120 unchanged)', $output);
        $this->assertStringContainsString('375000', $output);
        $this->assertStringContainsString('Jita cadence: every 15 minutes', $output);
    }

    public function test_complete_job_completes_or_fails_the_sweep(): void
    {
        Queue::fake([PrecomputeManufacturingCostsChunk::class]);
        $sweeps = app(MarketOrderSweepService::class);

        $ok = $sweeps->start(10000002);
        (new CompleteMarketOrderSweep($ok->sweep_id, true))->handle($sweeps);
        $this->assertSame(MarketOrderSweep::STATUS_COMPLETED, $ok->fresh()->status);

        $bad = $sweeps->start(10000043);
        (new CompleteMarketOrderSweep($bad->sweep_id, false))->handle($sweeps);
        $this->assertSame(MarketOrderSweep::STATUS_FAILED, $bad->fresh()->status);

        $this->assertSame('market', (new CompleteMarketOrderSweep('x', true))->queue);
    }
}
