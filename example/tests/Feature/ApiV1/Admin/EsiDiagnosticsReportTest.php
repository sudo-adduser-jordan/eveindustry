<?php

namespace Tests\Feature\ApiV1\Admin;

use App\Models\Character;
use App\Models\User;
use App\Services\Esi\Diagnostics\Streams\ArrayStream;
use App\Services\Esi\Diagnostics\Streams\EsiDiagnosticsStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EsiDiagnosticsReportTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/api/v1/admin/esi-diagnostics';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(EsiDiagnosticsStream::class, new ArrayStream);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'is_admin' => true, 'is_active' => true]));
        $this->travelTo(now()->setTime(12, 30));
    }

    private function metric(string $kind, int $status, int $requests, int $errors = 0, array $extra = []): void
    {
        static $n = 0;
        DB::table('esi_endpoint_metrics')->insert($extra + [
            'dimension_hash' => hash('sha256', 'm'.++$n), 'bucket_at' => now()->subHours(2)->startOfHour()->utc()->format('Y-m-d H:i:s'), 'operation_key' => 'get /characters/{character_id}/assets',
            'event_kind' => $kind, 'http_status' => $status, 'reason' => $status === 404 ? 'not_found' : 'ok', 'dataset' => 'assets', 'source_identity' => 'egress', 'limiter_group' => '',
            'request_count' => $requests, 'success_count' => $kind === 'http_attempt' && $status < 400 ? $requests : 0, 'error_count' => $errors, 'duration_sum_ms' => 0, 'duration_count' => 0, 'duration_max_ms' => 0,
        ]);
    }

    public function test_summary_uses_upstream_attempts_as_the_denominator(): void
    {
        $this->metric('http_attempt', 200, 90);
        $this->metric('http_attempt', 404, 8, 8);
        $this->metric('http_attempt', 429, 2, 2);
        $this->metric('cache_hit', 0, 500);
        $this->metric('local_deferral', 0, 40);
        DB::table('esi_budget_metrics')->insert(['dimension_hash' => str_repeat('c', 64), 'bucket_at' => now()->subHours(2)->startOfHour()->utc()->format('Y-m-d H:i:s'), 'granularity' => 'hour', 'mechanism' => 'legacy',
            'source_identity' => '', 'application_id' => '', 'limiter_group' => '', 'remaining_min' => 18, 'circuit_opened_count' => 2, 'pause_seconds' => 30]);

        $data = $this->getJson("$this->base/summary")->assertOk()->json('data');
        $this->assertSame(100, $data['upstream_requests']);
        $this->assertSame(10, $data['errors']);
        $this->assertEquals(10.0, $data['error_percent']);
        $this->assertSame([8, 2, 0], [$data['status_404'], $data['throttled_429'], $data['status_401']]);
        $this->assertSame(500, $data['cache_hits']);
        $this->assertSame(40, $data['local_deferrals']);
        $this->assertSame([2, 18, 30], [$data['protection_episodes'], $data['min_legacy_remaining'], $data['observed_pause_seconds']]);

        $filtered = $this->getJson("$this->base/summary?status=404")->json('data');
        $this->assertSame(8, $filtered['upstream_requests']);
    }

    public function test_empty_range_reports_null_percent_and_null_budget_not_zero(): void
    {
        $data = $this->getJson("$this->base/summary")->assertOk()->json('data');
        $this->assertSame(0, $data['upstream_requests']);
        $this->assertNull($data['error_percent']);
        $this->assertNull($data['min_legacy_remaining']);
    }

    public function test_trends_endpoints_and_reasons(): void
    {
        $this->metric('http_attempt', 200, 95);
        $this->metric('http_attempt', 404, 5, 5);
        $this->metric('http_attempt', 200, 10, 0, ['operation_key' => 'get /markets/{region_id}/orders']);

        $trends = $this->getJson("$this->base/trends")->assertOk()->json('data');
        $this->assertSame('hour', $trends['granularity']);
        $this->assertSame([110, 5, 5], [$trends['buckets'][0]['requests'], $trends['buckets'][0]['errors'], $trends['buckets'][0]['status_404']]);

        $endpoints = $this->getJson("$this->base/endpoints")->assertOk()->json('data');
        $this->assertSame('get /characters/{character_id}/assets', $endpoints['operations'][0]['operation']);
        $this->assertSame([100, 5], [$endpoints['operations'][0]['requests'], $endpoints['operations'][0]['errors']]);
        $this->assertEquals(50.0, $endpoints['operations'][0]['errors_per_1000']);
        $this->assertSame([404, 'not_found', 5], [$endpoints['reasons'][0]['http_status'], $endpoints['reasons'][0]['reason'], $endpoints['reasons'][0]['errors']]);
    }

    public function test_events_are_cursor_paged_filtered_and_linked_to_the_character(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'is_active' => true]);
        Character::create(['character_id' => 77, 'corporation_id' => 1, 'corporation_name' => 'C', 'user_id' => $user->id, 'name' => 'Pilot Seven', 'access_token' => 'x', 'refresh_token' => 'y', 'token_expires_at' => now()->addHour(), 'is_esi_active' => true]);
        foreach (range(1, 5) as $i) {
            DB::table('esi_diagnostic_events')->insert(['event_id' => 'E'.$i, 'occurred_at' => now()->subMinutes($i)->utc()->format('Y-m-d H:i:s'), 'kind' => 'http_attempt', 'http_status' => $i === 5 ? 403 : 404,
                'reason' => 'not_found', 'operation_key' => 'get /x', 'character_id' => $i % 2 ? 77 : 88, 'batch_fingerprint' => str_repeat('f', 64)]);
        }
        $first = $this->getJson("$this->base/events?per_page=2")->assertOk();
        $this->assertCount(2, $first->json('data'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $second = $this->getJson("$this->base/events?per_page=2&before_id=$cursor")->json();
        $this->assertLessThan($cursor, $second['data'][0]['id']);

        $mine = $this->getJson("$this->base/events?character_id=77")->json('data');
        $this->assertSame('Pilot Seven', $mine[0]['character_name']);
        $this->assertSame($user->id, $mine[0]['user_id']);
        $this->assertCount(1, $this->getJson("$this->base/events?status=403")->json('data'));
        $this->assertNull($this->getJson("$this->base/events?per_page=100")->json('meta.next_cursor'));

        $batches = $this->getJson("$this->base/batches")->assertOk()->json('data');
        $this->assertSame([5, 2], [$batches[0]['occurrences'], $batches[0]['characters']]);
    }

    public function test_range_and_filters_are_validated(): void
    {
        $this->getJson("$this->base/summary?from=2026-01-01&to=2026-06-01")->assertStatus(422);
        $this->getJson("$this->base/summary?from=2026-02-01&to=2026-01-01")->assertStatus(422);
        $this->getJson("$this->base/events?per_page=500")->assertStatus(422);
        $this->getJson("$this->base/events?reason=bogus")->assertStatus(422);
    }

    public function test_health_reports_an_uninitialised_collector_as_unhealthy(): void
    {
        $health = $this->getJson("$this->base/health")->assertOk()->json('data');
        $this->assertFalse($health['healthy']);
        $this->assertContains('collector_stale', $health['problems']);
        $this->assertNull($health['last_flushed_at']);
    }
}
