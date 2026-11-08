<?php

namespace Tests\Unit\Esi;

use App\Jobs\FlushEsiDiagnostics;
use App\Services\Esi\Diagnostics\EsiDiagnosticEvent;
use App\Services\Esi\Diagnostics\EsiDiagnosticsFlusher;
use App\Services\Esi\Diagnostics\EsiDiagnosticsHealth;
use App\Services\Esi\Diagnostics\EsiDiagnosticsRetention;
use App\Services\Esi\Diagnostics\Streams\ArrayStream;
use App\Services\Esi\Diagnostics\StreamSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EsiDiagnosticsFlusherTest extends TestCase
{
    use RefreshDatabase;

    private ArrayStream $stream;

    private StreamSink $sink;

    private EsiDiagnosticsFlusher $flusher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array', 'esi_diagnostics.enabled' => true]);
        Cache::flush();
        StreamSink::$uncountedLoss = 0;
        $this->stream = new ArrayStream;
        $this->sink = new StreamSink($this->stream);
        $this->flusher = new EsiDiagnosticsFlusher($this->stream);
    }

    private function push(array $attributes, string $at = '2026-10-25 10:15:30.123456'): void
    {
        $event = EsiDiagnosticEvent::make($attributes + ['source_identity' => 'egress', 'application_id' => 'app', 'method' => 'GET', 'operation_key' => 'get /characters/{character_id}/assets', 'response_received' => true]);
        $event['occurred_at'] = $at;
        $this->sink->push($event);
    }

    private function attempt(int $status, array $extra = [], string $at = '2026-10-25 10:15:30.123456'): void
    {
        $this->push(['kind' => 'http_attempt', 'http_status' => $status, 'reason' => $status < 400 ? 'ok' : 'not_found', 'duration_ms' => 100] + $extra, $at);
    }

    public function test_failures_become_detail_rows_and_every_attempt_is_counted(): void
    {
        $this->attempt(200);
        $this->attempt(304);
        $this->attempt(404);
        $this->push(['kind' => 'cache_hit', 'reason' => 'cache', 'response_received' => false]);
        $this->push(['kind' => 'local_deferral', 'reason' => 'error_limited', 'response_received' => false]);
        $this->flusher->run();

        $this->assertSame(1, DB::table('esi_diagnostic_events')->count());
        $this->assertSame(404, (int) DB::table('esi_diagnostic_events')->value('http_status'));
        $attempts = DB::table('esi_endpoint_metrics')->where('event_kind', 'http_attempt')->get();
        $this->assertSame(3, (int) $attempts->sum('request_count'));
        $this->assertSame(2, (int) $attempts->sum('success_count'));
        $this->assertSame(1, (int) $attempts->sum('error_count'));
        $this->assertSame(1, (int) DB::table('esi_endpoint_metrics')->where('event_kind', 'cache_hit')->value('request_count'));
        $this->assertSame(0, (int) DB::table('esi_endpoint_metrics')->where('event_kind', 'cache_hit')->value('error_count'));
        $this->assertSame(1, (int) DB::table('esi_endpoint_metrics')->where('event_kind', 'local_deferral')->value('request_count'));
        $this->assertSame(0, $this->stream->length(), 'acknowledged after commit');
    }

    public function test_second_flush_merges_into_the_same_hourly_row(): void
    {
        $this->attempt(200);
        $this->flusher->run();
        $this->attempt(200, [], '2026-10-25 10:50:00.000000');
        $this->flusher->run();
        $this->assertSame(1, DB::table('esi_endpoint_metrics')->count());
        $row = DB::table('esi_endpoint_metrics')->first();
        $this->assertSame(2, (int) $row->request_count);
        $this->assertSame(200, (int) $row->duration_sum_ms);
        $this->assertStringStartsWith('2026-10-25 10:00:00', $row->bucket_at);
    }

    public function test_budget_observations_preserve_unknowns_and_roll_up_minute_and_hour(): void
    {
        $this->attempt(200, ['legacy_remaining' => 90, 'legacy_reset_at' => 1_800_000_000]);
        $this->attempt(200, ['legacy_remaining' => 40], '2026-10-25 10:15:40.000000');
        $this->attempt(200, ['legacy_remaining' => 70], '2026-10-25 10:15:50.000000');
        $this->attempt(200); // no budget headers: no observation, not zero
        $this->push(['kind' => 'circuit_opened', 'reason' => 'error_limited', 'legacy_reset_at' => 1_800_000_100], '2026-10-25 10:15:55.000000');
        $this->push(['kind' => 'circuit_closed', 'reason' => 'circuit_expired_inferred', 'duration_ms' => 12000], '2026-10-25 10:16:10.000000');
        $this->flusher->run();

        $minute = DB::table('esi_budget_metrics')->where('granularity', 'minute')->where('mechanism', 'legacy')->orderBy('bucket_at')->get();
        $this->assertCount(2, $minute);
        $this->assertSame([3, 40, 90, 90, 70], [(int) $minute[0]->observations, (int) $minute[0]->remaining_min, (int) $minute[0]->remaining_max, (int) $minute[0]->remaining_first, (int) $minute[0]->remaining_last]);
        $this->assertSame(1, (int) $minute[0]->circuit_opened_count);
        $this->assertSame(1, (int) $minute[1]->circuit_closed_count);
        $this->assertSame(12, (int) $minute[1]->pause_seconds);
        $this->assertNull($minute[1]->remaining_min);
        $hour = DB::table('esi_budget_metrics')->where('granularity', 'hour')->get();
        $this->assertCount(1, $hour);
        $this->assertSame(3, (int) $hour[0]->observations);
    }

    public function test_token_group_budget_is_kept_separate(): void
    {
        $this->attempt(200, ['limiter_group' => 'char-asset', 'token_remaining' => 140, 'token_used' => 2]);
        $this->attempt(200, ['limiter_group' => 'market', 'token_remaining' => 100, 'token_used' => 1]);
        $this->flusher->run();
        $groups = DB::table('esi_budget_metrics')->where('mechanism', 'token')->where('granularity', 'minute')->pluck('used_sum', 'limiter_group')->all();
        $this->assertEquals(['char-asset' => 2, 'market' => 1], $groups);
    }

    public function test_sql_rollback_leaves_checkpoint_and_stream_untouched_then_replay_counts_once(): void
    {
        $this->attempt(404);
        $this->attempt(200);
        $failing = new class($this->stream) extends EsiDiagnosticsFlusher
        {
            protected function apply(array $events): void
            {
                parent::apply($events);
                throw new \RuntimeException('database went away');
            }
        };
        try {
            $failing->flushBatch();
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame(0, DB::table('esi_diagnostic_events')->count());
        $this->assertSame(0, DB::table('esi_endpoint_metrics')->count());
        $this->assertNull(DB::table('esi_telemetry_checkpoints')->value('last_stream_id'));
        $this->assertSame(2, $this->stream->length());

        $this->flusher->run();
        $this->assertSame(1, DB::table('esi_diagnostic_events')->count());
        $this->assertSame(2, (int) DB::table('esi_endpoint_metrics')->sum('request_count'));
    }

    public function test_crash_after_commit_before_ack_is_not_counted_twice(): void
    {
        $this->attempt(404);
        $stream = new class extends ArrayStream
        {
            public bool $failTrim = true;

            public function trimThrough(string $id): void
            {
                if ($this->failTrim) {
                    throw new \RuntimeException('redis gone');
                }
                parent::trimThrough($id);
            }
        };
        $stream->add(json_encode(array_merge(EsiDiagnosticEvent::make(['kind' => 'http_attempt', 'http_status' => 404, 'reason' => 'not_found']), ['occurred_at' => '2026-10-25 10:00:00.000000'])));
        $flusher = new EsiDiagnosticsFlusher($stream);
        $flusher->flushBatch();
        $this->assertSame(1, $stream->length(), 'entry survived the failed acknowledgement');
        $flusher->flushBatch();
        $flusher->flushBatch();
        $this->assertSame(1, (int) DB::table('esi_endpoint_metrics')->sum('request_count'));
        $this->assertSame(1, DB::table('esi_diagnostic_events')->count());
        $stream->failTrim = false;
        $flusher->flushBatch();
    }

    public function test_concurrent_flusher_is_refused_by_the_lease(): void
    {
        $this->attempt(200);
        $lock = Cache::store('array')->lock('esi.diagnostics.flusher', 60);
        $this->assertTrue($lock->get());
        $result = $this->flusher->run();
        $this->assertTrue($result['locked']);
        $this->assertSame(0, DB::table('esi_endpoint_metrics')->count());
        $lock->release();
        $this->assertFalse($this->flusher->run()['locked']);
        $this->assertSame(1, (int) DB::table('esi_endpoint_metrics')->sum('request_count'));
    }

    public function test_buffer_failure_is_counted_as_loss_and_never_throws(): void
    {
        $broken = new class extends ArrayStream
        {
            public bool $countable = true;

            public function add(string $json): void
            {
                throw new \RuntimeException('redis down');
            }

            public function recordDrop(): void
            {
                if (! $this->countable) {
                    throw new \RuntimeException('redis still down');
                }
                parent::recordDrop();
            }
        };
        $sink = new StreamSink($broken);
        $sink->push(['kind' => 'http_attempt']);
        $this->assertSame(1, $broken->pendingDrops());
        $broken->countable = false;
        $sink->push(['kind' => 'http_attempt']);
        $this->assertSame(1, StreamSink::$uncountedLoss);

        (new EsiDiagnosticsFlusher($broken))->run();
        $this->assertSame(1, (int) DB::table('esi_telemetry_checkpoints')->value('dropped_events'));
        $this->assertSame(0, $broken->pendingDrops());
    }

    public function test_entries_evicted_unread_are_recorded_as_a_gap_once(): void
    {
        $this->attempt(200);
        $this->flusher->run();
        foreach (range(1, 5) as $i) {
            $this->attempt(200);
        }
        $this->stream->evictOldest(3);
        $this->flusher->run();
        $this->flusher->run();
        $row = DB::table('esi_telemetry_checkpoints')->first();
        $this->assertSame(1, (int) $row->gap_count);
        $this->assertSame('trimmed_unread', $row->last_gap_reason);
        $this->assertSame(1 + 2, (int) DB::table('esi_endpoint_metrics')->sum('request_count'), 'only the surviving entries are counted');
    }

    public function test_stream_reset_is_detected_and_collection_resumes(): void
    {
        $this->attempt(200);
        $this->flusher->run();
        $this->stream->reset();
        $this->attempt(200);
        $this->flusher->run();
        $row = DB::table('esi_telemetry_checkpoints')->first();
        $this->assertSame('stream_reset', $row->last_gap_reason);
        $this->assertSame(2, (int) DB::table('esi_endpoint_metrics')->sum('request_count'));
    }

    public function test_malformed_entries_are_counted_not_stored(): void
    {
        $this->stream->add('{not json');
        $this->stream->add(json_encode(['kind' => 'http_attempt', 'event_id' => 'x', 'occurred_at' => 'yesterday']));
        $this->attempt(200);
        $this->flusher->run();
        $this->assertSame(2, (int) DB::table('esi_telemetry_checkpoints')->value('dropped_events'));
        $this->assertSame(1, (int) DB::table('esi_endpoint_metrics')->sum('request_count'));
    }

    public function test_run_is_bounded_and_the_job_continues_only_while_backlog_remains(): void
    {
        config(['esi_diagnostics.flush.batch' => 2, 'esi_diagnostics.flush.max_seconds' => 0]);
        foreach (range(1, 5) as $i) {
            $this->attempt(200);
        }
        Queue::fake();
        (new FlushEsiDiagnostics)->handle($this->flusher);
        $this->assertSame(3, $this->stream->length());
        Queue::assertPushed(FlushEsiDiagnostics::class, 1);

        config(['esi_diagnostics.flush.max_seconds' => 45]);
        Queue::fake();
        (new FlushEsiDiagnostics)->handle($this->flusher);
        $this->assertSame(0, $this->stream->length());
        Queue::assertNothingPushed();
    }

    public function test_retention_prunes_in_batches_and_counts_expiry_separately(): void
    {
        config(['esi_diagnostics.retention.prune_batch' => 2]);
        $old = now()->subDays(20)->format('Y-m-d H:i:s');
        foreach (range(1, 5) as $i) {
            DB::table('esi_diagnostic_events')->insert(['event_id' => 'OLD'.$i, 'occurred_at' => $old, 'kind' => 'http_attempt']);
        }
        DB::table('esi_diagnostic_events')->insert(['event_id' => 'NEW1', 'occurred_at' => now()->format('Y-m-d H:i:s'), 'kind' => 'http_attempt']);
        $row = ['dimension_hash' => str_repeat('a', 64), 'source_identity' => '', 'application_id' => '', 'mechanism' => 'legacy', 'limiter_group' => ''];
        DB::table('esi_budget_metrics')->insert($row + ['bucket_at' => now()->subDays(10)->format('Y-m-d H:i:s'), 'granularity' => 'minute']);
        DB::table('esi_budget_metrics')->insert(['dimension_hash' => str_repeat('b', 64)] + $row + ['bucket_at' => now()->subDays(10)->format('Y-m-d H:i:s'), 'granularity' => 'hour']);

        $deleted = (new EsiDiagnosticsRetention)->prune();
        $this->assertSame(5, $deleted['events']);
        $this->assertSame(1, $deleted['budget_minute']);
        $this->assertSame(0, $deleted['budget_hour'], 'hourly summaries outlive minute rows');
        $this->assertSame(1, DB::table('esi_diagnostic_events')->count());
        $this->assertSame(6, (int) DB::table('esi_telemetry_checkpoints')->value('expired_rows'));
        $this->assertSame(0, (int) DB::table('esi_telemetry_checkpoints')->value('dropped_events'));
    }

    public function test_health_marks_a_never_run_or_stale_collector_unhealthy(): void
    {
        $health = new EsiDiagnosticsHealth($this->stream);
        $this->assertContains('collector_stale', $health->report()['problems']);

        $this->attempt(200);
        $this->flusher->run();
        $report = $health->report();
        $this->assertTrue($report['healthy']);
        $this->assertSame(0, $report['backlog']);

        DB::table('esi_telemetry_checkpoints')->update(['last_flushed_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $this->assertContains('collector_stale', $health->report()['problems']);

        config(['esi_diagnostics.redis.max_entries' => 2]);
        DB::table('esi_telemetry_checkpoints')->update(['last_flushed_at' => gmdate('Y-m-d H:i:s')]);
        $this->attempt(200);
        $this->attempt(200);
        $this->assertContains('buffer_near_cap', $health->report()['problems']);
    }
}
