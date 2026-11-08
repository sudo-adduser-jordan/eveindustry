<?php

namespace Tests\Unit\Esi;

use App\Services\Esi\Diagnostics\EsiDiagnosticsFlusher;
use App\Services\Esi\Diagnostics\Streams\RedisStream;
use App\Services\Esi\Diagnostics\StreamSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Opt-in: disposable Redis only (ESI_REDIS_INTEGRATION=1). */
class EsiDiagnosticsRedisStreamTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ESI_REDIS_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires ESI_REDIS_INTEGRATION=1 and a disposable Redis.');
        }
        config(['esi.coordination_store' => 'array', 'esi_diagnostics.enabled' => true]);
        $this->key = 'esi:diag:test:'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->key)) {
            foreach ([$this->key, $this->key.':epoch', $this->key.':drops'] as $key) {
                Redis::connection('default')->del($key);
            }
        }
        parent::tearDown();
    }

    public function test_stream_semantics_and_flush_against_real_redis(): void
    {
        $stream = new RedisStream('default', $this->key, 5);
        $epoch = $stream->epoch();
        $this->assertSame($epoch, $stream->epoch(), 'epoch is stable');

        $sink = new StreamSink($stream);
        foreach ([200, 404, 200] as $status) {
            $sink->push(\App\Services\Esi\Diagnostics\EsiDiagnosticEvent::make(['kind' => 'http_attempt', 'http_status' => $status, 'reason' => $status === 404 ? 'not_found' : 'ok', 'operation_key' => 'get /x']));
        }
        $this->assertSame(3, $stream->length());
        $ids = array_keys($stream->read(null, 10));
        $this->assertCount(3, $ids);
        $this->assertCount(2, $stream->read($ids[0], 10), 'exclusive of the given id');

        (new EsiDiagnosticsFlusher($stream))->run();
        $this->assertSame(0, $stream->length());
        $this->assertSame(3, (int) DB::table('esi_endpoint_metrics')->sum('request_count'));
        $this->assertSame(1, DB::table('esi_diagnostic_events')->count());

        // Cap eviction of unread entries is detected (needs Redis 7 for max-deleted-entry-id).
        // MAXLEN ~ trims whole nodes (about 100 entries each), so overshoot the cap well past one node.
        foreach (range(1, 350) as $i) {
            $sink->push(\App\Services\Esi\Diagnostics\EsiDiagnosticEvent::make(['kind' => 'http_attempt', 'http_status' => 200, 'operation_key' => 'get /x']));
        }
        (new EsiDiagnosticsFlusher($stream))->run();
        $this->assertGreaterThanOrEqual(1, (int) DB::table('esi_telemetry_checkpoints')->value('gap_count'));

        $stream->recordDrop();
        $this->assertSame(1, $stream->pendingDrops());
        $stream->acknowledgeDrops(1);
        $this->assertSame(0, $stream->pendingDrops());
    }
}
