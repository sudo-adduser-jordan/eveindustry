<?php

namespace Tests\Unit\Esi;

use App\Models\Character;
use App\Models\User;
use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\EsiRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiHttpClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array']);
        Cache::flush();
    }

    private function character(): Character
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'transport@test.test', 'password' => 'test', 'is_active' => true]);

        return Character::create(['character_id' => 123, 'corporation_id' => 98000, 'corporation_name' => 'Corp', 'user_id' => $user->id, 'name' => 'Pilot', 'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'is_esi_active' => true]);
    }

    public function test_short_intermediate_pages_and_more_than_twenty_pages_are_complete(): void
    {
        Http::fake(fn ($request) => Http::response([['item_id' => (int) $request['page']]], 200, ['X-Pages' => '23']));
        $data = app(EsiHttpClient::class)->authenticatedPaginatedRequest('/characters/123/assets/', $this->character());
        $this->assertCount(23, $data);
        $this->assertSame(23, $data[22]['item_id']);
        Http::assertSentCount(23);
    }

    public function test_failed_final_page_throws_and_preserves_status_and_headers(): void
    {
        Http::fakeSequence()->push([['item_id' => 1]], 200, ['X-Pages' => '2'])->push(['error' => 'Unavailable'], 503, ['Retry-After' => '12']);
        try {
            app(EsiHttpClient::class)->authenticatedPaginatedRequest('/characters/123/assets/', $this->character());
            $this->fail('Partial inventory must not return.');
        } catch (EsiRequestException $e) {
            $this->assertSame('transient', $e->outcome);
            $this->assertTrue($e->retryable());
            $this->assertSame(12, $e->retryAfterSeconds);
            $this->assertSame(503, $e->response->status());
            $this->assertSame('12', $e->response->header('Retry-After'));
        }
    }

    public function test_changed_page_counts_abort_incomplete_inventory(): void
    {
        Http::fakeSequence()->push([['item_id' => 1]], 200, ['X-Pages' => '2'])->push([['item_id' => 2]], 200, ['X-Pages' => '3']);
        $this->expectException(EsiRequestException::class);
        app(EsiHttpClient::class)->authenticatedPaginatedRequest('/characters/123/assets/', $this->character());
    }

    public function test_changed_last_modified_aborts_incomplete_inventory(): void
    {
        Http::fakeSequence()->push([['item_id' => 1]], 200, ['X-Pages' => '2', 'Last-Modified' => 'Mon, 01 Jun 2026 00:00:00 GMT'])->push([['item_id' => 2]], 200, ['X-Pages' => '2', 'Last-Modified' => 'Mon, 01 Jun 2026 00:01:00 GMT']);
        $this->expectException(EsiRequestException::class);
        app(EsiHttpClient::class)->authenticatedPaginatedRequest('/characters/123/assets/', $this->character());
    }

    public function test_complete_empty_inventory_is_distinct_from_failure(): void
    {
        Http::fake(['*' => Http::response([], 200, ['X-Pages' => '1'])]);
        $this->assertSame([], app(EsiHttpClient::class)->authenticatedPaginatedRequest('/characters/123/assets/', $this->character()));
    }

    public function test_304_without_a_stored_body_cannot_become_empty_inventory(): void
    {
        Http::fake(['*' => Http::response('', 304)]);
        $this->expectException(EsiRequestException::class);
        app(EsiHttpClient::class)->authenticatedPaginatedRequest('/characters/123/assets/', $this->character());
    }

    public function test_missing_scope_401_never_refreshes_or_deactivates_character(): void
    {
        $character = $this->character();
        Http::fake(['*' => Http::response(['error' => 'Unauthorized - Token is not valid for any required scope: esi-industry.read_corporation_jobs.v1'], 401)]);
        try {
            app(EsiHttpClient::class)->authenticatedResponse('get', '/corporations/1/industry/jobs/', $character);
            $this->fail('Missing scope should be classified.');
        } catch (EsiRequestException $e) {
            $this->assertSame('missing_scope', $e->outcome);
            $this->assertFalse($e->retryable());
        }
        Http::assertSentCount(1);
        $this->assertTrue($character->fresh()->is_esi_active);
    }

    public function test_public_and_authenticated_requests_always_identify_client_and_support_post_params(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $client = app(EsiHttpClient::class);
        $client->publicRequest('get', '/universe/types/34/');
        $client->authenticatedResponse('post', '/characters/123/assets/names/', $this->character(), false, [100, 101]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->data() === [100, 101] && $request->hasHeader('Authorization', 'Bearer access'));
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertNotEmpty($request->header('User-Agent'));
            $this->assertSame(['2025-12-16'], $request->header('X-Compatibility-Date'));
        }
    }

    public function test_upstream_429_retry_after_is_preserved_and_suppresses_next_request(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '7'])]);
        $client = app(EsiHttpClient::class);
        try {
            $client->publicResponse('get', '/markets/10000002/orders/');
            $this->fail('429 must throw.');
        } catch (EsiRequestException $e) {
            $this->assertSame(7, $e->retryAfterSeconds);
        }
        $this->assertNull($client->publicRequest('get', '/markets/10000002/orders/'));
        Http::assertSentCount(1);
    }

    public function test_shared_circuit_prevents_even_expired_token_refresh(): void
    {
        $character = $this->character();
        $character->update(['token_expires_at' => now()->subMinute()]);
        Cache::put('esi.error_circuit.until', now()->timestamp + 40, 40);
        Http::fake();
        $this->assertNull(app(EsiHttpClient::class)->authenticatedRequest('get', '/characters/123/assets/', $character));
        Http::assertNothingSent();
    }

    public function test_stale_model_cannot_serve_private_cache_after_persisted_deactivation(): void
    {
        $character = $this->character();
        Http::fake(['*' => Http::response([], 200, ['Cache-Control' => 'max-age=600'])]);
        $client = app(EsiHttpClient::class);
        $client->authenticatedResponse('get', '/characters/123/assets/', $character);
        Character::whereKey(123)->update(['is_esi_active' => false]);
        try {
            $client->authenticatedResponse('get', '/characters/123/assets/', $character);
            $this->fail('A persisted inactive character must not read a private representation.');
        } catch (EsiRequestException $e) {
            $this->assertSame('credential', $e->outcome);
        }
        Http::assertSentCount(1);
    }

    public function test_authoritative_group_header_is_used_for_subsequent_requests(): void
    {
        Http::fake(['*' => Http::response([], 429, ['X-Ratelimit-Group' => 'new-group', 'X-Ratelimit-Limit' => '100/15m', 'Retry-After' => '8'])]);
        $client = app(EsiHttpClient::class);
        $client->publicRequest('get', '/universe/types/34/');
        $this->assertSame('new-group', $client->resolveRateLimitGroup('/universe/types/34/'));
        $this->assertNull($client->publicRequest('get', '/universe/types/34/'));
        Http::assertSentCount(1);
    }

    public function test_legacy_universe_routes_do_not_inherit_old_request_count_limit(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $client = app(EsiHttpClient::class);
        for ($i = 0; $i < 301; $i++) {
            $this->assertNotNull($client->publicRequest('get', '/universe/types/'.$i.'/'));
        }
        Http::assertSentCount(301);
    }
}
