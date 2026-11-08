<?php

namespace Tests\Unit\Esi;

use App\Jobs\FetchRegionHistoryChunk;
use App\Models\Character;
use App\Models\User;
use App\Services\Esi\CharacterClient;
use App\Services\Esi\Diagnostics\ArrayDiagnosticsSink;
use App\Services\Esi\Diagnostics\EsiDiagnosticsContext;
use App\Services\Esi\Diagnostics\EsiDiagnosticsRecorder;
use App\Services\Esi\Diagnostics\EsiDiagnosticsSink;
use App\Services\Esi\Diagnostics\EsiErrorClassifier;
use App\Services\Esi\Diagnostics\EsiRouteNormalizer;
use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\EsiRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private ArrayDiagnosticsSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array', 'esi_diagnostics.enabled' => true]);
        Cache::flush();
        $this->sink = new ArrayDiagnosticsSink;
        $this->app->instance(EsiDiagnosticsSink::class, $this->sink);
        $this->app->forgetInstance(EsiDiagnosticsRecorder::class);
        $this->app->forgetInstance(EsiHttpClient::class);
    }

    private function character(): Character
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'diag@test.test', 'password' => 'test', 'is_active' => true]);

        return Character::create(['character_id' => 123, 'corporation_id' => 98000, 'corporation_name' => 'Corp', 'user_id' => $user->id, 'name' => 'Pilot', 'access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'token_expires_at' => now()->addHour(), 'is_esi_active' => true]);
    }

    private function kinds(): array
    {
        return array_column($this->sink->events, 'kind');
    }

    public function test_classifier_maps_to_allowlist(): void
    {
        $this->assertSame('ok', EsiErrorClassifier::classify(200));
        $this->assertSame('not_modified', EsiErrorClassifier::classify(304));
        $this->assertSame('throttled_420', EsiErrorClassifier::classify(420));
        $this->assertSame('throttled_429', EsiErrorClassifier::classify(429));
        $this->assertSame('upstream_failure', EsiErrorClassifier::classify(503));
        $this->assertSame('missing_scope', EsiErrorClassifier::classify(403, 'Token is not valid for scope esi-assets.read_assets.v1'));
        $this->assertSame('missing_role', EsiErrorClassifier::classify(403, 'Character does not have required role(s)'));
        $this->assertSame('corporation_mismatch', EsiErrorClassifier::classify(403, 'Character is not in the corporation'));
        $this->assertSame('invalid_id_batch', EsiErrorClassifier::classify(400, 'Invalid IDs in the request'));
        $this->assertSame('not_found', EsiErrorClassifier::classify(404, 'Type not found'));
        $this->assertSame('connection', EsiErrorClassifier::classify(null));
    }

    public function test_route_normalizer_strips_ids_and_query(): void
    {
        $this->assertSame('get /characters/{character_id}/assets', EsiRouteNormalizer::normalize('GET', '/latest/characters/123/assets/?page=2'));
        $this->assertSame('get /unknown/{id}/thing', EsiRouteNormalizer::normalize('get', '/unknown/9988/thing'));
    }

    public function test_success_and_failure_each_record_one_attempt_without_secrets(): void
    {
        Http::fakeSequence()
            ->push([['item_id' => 1]], 200, ['X-Esi-Error-Limit-Remain' => '95', 'X-Esi-Error-Limit-Reset' => '30'])
            ->push(['error' => 'Type not found'], 404, ['X-Esi-Error-Limit-Remain' => '94']);
        $client = app(EsiHttpClient::class);
        $character = $this->character();
        $client->authenticatedResponse('get', '/characters/123/assets/?page=1', $character);
        try {
            $client->authenticatedResponse('get', '/characters/123/assets/?page=2', $character);
            $this->fail('expected 404');
        } catch (EsiRequestException $e) {
        }
        $attempts = array_values(array_filter($this->sink->events, fn ($e) => $e['kind'] === 'http_attempt'));
        $this->assertCount(2, $attempts);
        $this->assertSame([200, 404], array_column($attempts, 'http_status'));
        $this->assertSame(['ok', 'not_found'], array_column($attempts, 'reason'));
        $this->assertSame(95, $attempts[0]['legacy_remaining']);
        $this->assertNull($attempts[1]['legacy_reset_seconds'], 'missing header stays null, not zero');
        $this->assertSame('get /characters/{character_id}/assets', $attempts[0]['operation_key']);
        $this->assertSame(123, $attempts[0]['character_id']);
        $json = json_encode($this->sink->events);
        $this->assertStringNotContainsString('secret-access', $json);
        $this->assertStringNotContainsString('page=', $json);
    }

    public function test_connection_failure_is_recorded_without_status(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        try {
            app(EsiHttpClient::class)->publicResponse('get', '/universe/types/34/');
        } catch (EsiRequestException $e) {
        }
        $failure = collect($this->sink->events)->firstWhere('kind', 'connection_failure');
        $this->assertNotNull($failure);
        $this->assertNull($failure['http_status']);
        $this->assertFalse($failure['response_received']);
    }

    public function test_cache_hit_is_not_an_upstream_attempt_and_local_deferral_sends_nothing(): void
    {
        Http::fake(['*' => Http::response(['name' => 'Tritanium'], 200, ['Cache-Control' => 'public, max-age=300'])]);
        $client = app(EsiHttpClient::class);
        $client->publicResponse('get', '/universe/types/34/');
        $client->publicResponse('get', '/universe/types/34/');
        $this->assertSame(['cache_miss', 'http_attempt', 'cache_hit'], $this->kinds());
        Http::assertSentCount(1);

        app(EsiHttpClient::class)->rateLimiter()->store()->put('esi.error_circuit.until', now()->timestamp + 60, 60);
        $this->sink->events = [];
        try {
            $client->authenticatedResponse('get', '/characters/123/assets/', $this->character());
            $this->fail('expected deferral');
        } catch (EsiRequestException $e) {
        }
        // The cache lookup misses, then the circuit withholds the request locally.
        $this->assertSame(['cache_miss', 'local_deferral'], $this->kinds());
        Http::assertSentCount(1);
    }

    public function test_circuit_opening_and_inferred_close_are_distinct_events(): void
    {
        Http::fake(['*' => Http::response(['error' => 'x'], 420, ['X-Esi-Error-Limit-Remain' => '0', 'X-Esi-Error-Limit-Reset' => '5'])]);
        $client = app(EsiHttpClient::class);
        try {
            $client->publicResponse('get', '/universe/types/34/');
        } catch (EsiRequestException $e) {
        }
        $this->assertContains('circuit_opened', $this->kinds());
        $this->travel(10)->seconds();
        $client->esiErrorResetSeconds();
        $client->esiErrorResetSeconds();
        $closed = array_filter($this->sink->events, fn ($e) => $e['kind'] === 'circuit_closed');
        $this->assertCount(1, $closed);
        $this->assertSame('circuit_expired_inferred', array_values($closed)[0]['reason']);
    }

    public function test_disabled_flag_records_nothing(): void
    {
        config(['esi_diagnostics.enabled' => false]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        app(EsiHttpClient::class)->publicResponse('get', '/universe/types/34/');
        $this->assertSame([], $this->sink->events);
    }

    public function test_sink_failure_cannot_change_the_esi_outcome(): void
    {
        $this->app->instance(EsiDiagnosticsSink::class, new class implements EsiDiagnosticsSink
        {
            public function push(array $event): void
            {
                throw new \RuntimeException('redis down');
            }
        });
        $this->app->forgetInstance(EsiDiagnosticsRecorder::class);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $response = app(EsiHttpClient::class)->publicResponse('get', '/universe/types/34/');
        $this->assertSame(200, $response->status());
        $this->assertGreaterThan(0, app(EsiDiagnosticsRecorder::class)->dropped);
    }

    public function test_context_is_scoped_and_oversized_events_are_dropped(): void
    {
        $context = app(EsiDiagnosticsContext::class);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $context->with(['dataset' => 'asset_names', 'job_class' => 'App\\Jobs\\X', 'bogus' => 'ignored'], function () {
            app(EsiHttpClient::class)->publicResponse('get', '/universe/types/35/');
        });
        $event = collect($this->sink->events)->firstWhere('kind', 'http_attempt');
        $this->assertSame('asset_names', $event['dataset']);
        $this->assertArrayNotHasKey('bogus', $event);
        $this->assertSame([], $context->get());

        config(['esi_diagnostics.max_event_bytes' => 50]);
        $before = count($this->sink->events);
        app(EsiHttpClient::class)->publicResponse('get', '/universe/types/36/');
        $this->assertCount($before, $this->sink->events);
        $this->assertGreaterThan(0, app(EsiDiagnosticsRecorder::class)->dropped);
    }

    public function test_batch_fingerprint_is_order_independent_and_not_the_ids(): void
    {
        $a = EsiDiagnosticsContext::fingerprint([3, 1, 2]);
        $this->assertSame($a, EsiDiagnosticsContext::fingerprint([2, 3, 1, 1]));
        $this->assertStringNotContainsString('1,2,3', $a);
        $this->assertSame(64, strlen($a));
    }

    public function test_asset_name_batches_carry_dataset_size_and_a_stable_fingerprint(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid IDs in the request'], 400)]);
        $character = $this->character();
        $client = app(CharacterClient::class);
        foreach ([[3, 1, 2], [2, 3, 1]] as $ids) {
            try {
                $client->getCharacterAssetNames($character, $ids);
            } catch (EsiRequestException $e) {
                // The snapshot flags unfinished enrichment; the attempt is what matters here.
            }
        }
        $events = array_values(array_filter($this->sink->events, fn ($e) => $e['kind'] === 'http_attempt'));
        $this->assertCount(2, $events);
        $this->assertSame(['asset_names', 3, 'invalid_id_batch'], [$events[0]['dataset'], $events[0]['batch_size'], $events[0]['reason']]);
        $this->assertSame($events[0]['batch_fingerprint'], $events[1]['batch_fingerprint']);
        $this->assertStringNotContainsString('1,2,3', json_encode($events));
        $this->assertSame([], app(EsiDiagnosticsContext::class)->get());
    }

    public function test_a_queued_job_gets_its_class_and_dataset_and_the_direct_history_path_is_captured(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        FetchRegionHistoryChunk::dispatchSync(10000002, [34]);
        $event = collect($this->sink->events)->firstWhere('kind', 'http_attempt');
        $this->assertNotNull($event);
        $this->assertSame('get /markets/{id}/history', $event['operation_key']);
        $this->assertSame('fetch_region_history_chunk', $event['dataset']);
        $this->assertSame(FetchRegionHistoryChunk::class, $event['job_class']);
        $this->assertSame('not_found', $event['reason']);
        $this->assertSame([], app(EsiDiagnosticsContext::class)->get(), 'cleared after the job');
    }
}
