<?php

namespace Tests\Unit\Esi;

use App\Models\Character;
use App\Services\Esi\EsiHttpCache;
use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\EsiRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiHttpCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array', 'esi.http_cache_store' => 'array']);
        Cache::flush();
        $this->freezeTime();
    }

    private function character(int $id = 123): Character
    {
        return new Character(['character_id' => $id, 'user_id' => 1, 'corporation_id' => 98000, 'access_token' => 'private-token', 'refresh_token' => 'secret-refresh', 'token_expires_at' => now()->addHour(), 'is_esi_active' => true, 'scopes' => ['a', 'b'], 'roles' => ['Accountant']]);
    }

    public function test_fresh_representation_skips_http_and_does_not_claim_a_new_source_timestamp(): void
    {
        Http::fake(['*' => Http::response(['name' => 'A'], 200, ['Cache-Control' => 'public, max-age=60', 'ETag' => '"one"', 'Date' => now()->toRfc7231String()])]);
        $client = app(EsiHttpClient::class);
        $first = $client->publicResponse('get', '/universe/types/34/');
        $this->travel(20)->seconds();
        $second = $client->publicResponse('get', '/universe/types/34/');
        $this->assertSame($first->data(), $second->data());
        $this->assertSame($first->sourceMetadata, $second->sourceMetadata);
        $this->assertSame('20', $second->header('Age'));
        Http::assertSentCount(1);
    }

    public function test_stale_etag_revalidation_reuses_body_and_refreshes_headers_together(): void
    {
        $date = now()->toRfc7231String();
        Http::fakeSequence()->push([['item_id' => 1]], 200, ['Cache-Control' => 'max-age=10', 'ETag' => '"one"', 'Date' => $date, 'X-Pages' => '1'])->push('', 304, ['Cache-Control' => 'max-age=30', 'ETag' => '"two"', 'X-Pages' => '1']);
        $client = app(EsiHttpClient::class);
        $client->publicResponse('get', '/universe/types/');
        $this->travel(11)->seconds();
        $response = $client->publicResponse('get', '/universe/types/');
        $this->assertSame([['item_id' => 1]], $response->data());
        $this->assertSame(200, $response->status());
        $this->assertSame('"two"', $response->header('ETag'));
        $this->assertSame('1', $response->header('X-Pages'));
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"one"'));
        $client->publicResponse('get', '/universe/types/');
        Http::assertSentCount(2);
    }

    public function test_date_age_and_cache_control_override_later_expires(): void
    {
        Http::fake(['*' => Http::response([], 200, ['Cache-Control' => 'max-age=60', 'Age' => '30', 'Date' => now()->subSeconds(20)->toRfc7231String(), 'Expires' => now()->addDay()->toRfc7231String()])]);
        $client = app(EsiHttpClient::class);
        $client->publicResponse('get', '/universe/types/');
        $this->travel(29)->seconds();
        $client->publicResponse('get', '/universe/types/');
        Http::assertSentCount(1);
        $this->travel(2)->seconds();
        $client->publicResponse('get', '/universe/types/');
        Http::assertSentCount(2);
    }

    public function test_event_driven_skills_no_cache_revalidates_even_with_far_future_expires(): void
    {
        Http::fakeSequence()->push(['skills' => []], 200, ['Cache-Control' => 'no-cache', 'ETag' => '"skills"', 'Expires' => now()->addDay()->toRfc7231String()])->push('', 304, ['Cache-Control' => 'no-cache']);
        $client = app(EsiHttpClient::class);
        $character = $this->character();
        $client->authenticatedResponse('get', '/characters/123/skills/', $character);
        $this->assertSame(['skills' => []], $client->authenticatedResponse('get', '/characters/123/skills/', $character)->data());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"skills"'));
    }

    public function test_failure_preserves_old_body_for_later_validation_without_serving_it_as_success(): void
    {
        Http::fakeSequence()->push(['name' => 'A'], 200, ['Cache-Control' => 'max-age=1', 'ETag' => '"one"'])->push([], 503)->push('', 304, ['Cache-Control' => 'max-age=30']);
        $client = app(EsiHttpClient::class);
        $client->publicResponse('get', '/universe/types/34/');
        $this->travel(2)->seconds();
        try {
            $client->publicResponse('get', '/universe/types/34/');
            $this->fail('A stale body must not hide an upstream failure.');
        } catch (EsiRequestException $e) {
            $this->assertSame('transient', $e->outcome);
        }
        $this->assertSame(['name' => 'A'], $client->publicResponse('get', '/universe/types/34/')->data());
        Http::assertSentCount(3);
    }

    public function test_missing_body_304_throws_incomplete_on_typed_path(): void
    {
        Http::fake(['*' => Http::response('', 304, ['ETag' => '"old"'])]);
        $this->expectException(EsiRequestException::class);
        app(EsiHttpClient::class)->publicResponse('get', '/universe/types/34/', null, ['If-None-Match' => '"old"']);
    }

    public function test_authenticated_context_language_and_compatibility_are_isolated_but_query_order_is_canonical(): void
    {
        Http::fake(['*' => Http::response([], 200, ['Cache-Control' => 'max-age=60'])]);
        $client = app(EsiHttpClient::class);
        $character = $this->character();
        $client->authenticatedResponse('get', '/characters/123/assets/?b=2&a=1', $character);
        $client->authenticatedResponse('get', '/characters/123/assets/', $character, false, ['a' => 1, 'b' => 2]);
        Http::assertSentCount(1);
        $client->authenticatedResponse('get', '/characters/123/assets/?a=1&b=2', $this->character(456));
        $character->scopes = ['a'];
        $client->authenticatedResponse('get', '/characters/123/assets/?a=1&b=2', $character);
        $character->corporation_id = 99000;
        $client->authenticatedResponse('get', '/characters/123/assets/?a=1&b=2', $character);
        $client->authenticatedResponse('get', '/characters/123/assets/?a=1&b=2', $character, false, null, ['Accept-Language' => 'de']);
        config(['esi.compatibility_date' => '2026-01-01']);
        $client->authenticatedResponse('get', '/characters/123/assets/?a=1&b=2', $character);
        Http::assertSentCount(6);
    }

    public function test_no_headers_and_no_store_responses_are_not_cached(): void
    {
        Http::fakeSequence()->push([], 200)->push([], 200)->push([], 200, ['Cache-Control' => 'no-store', 'ETag' => '"secret"'])->push([], 200);
        $client = app(EsiHttpClient::class);
        $client->publicResponse('get', '/universe/types/34/');
        $client->publicResponse('get', '/universe/types/34/');
        $client->publicResponse('get', '/universe/types/35/');
        $client->publicResponse('get', '/universe/types/35/');
        Http::assertSentCount(4);
        $this->assertNull(app(EsiHttpCache::class)->metadata('/universe/types/35/'));
    }

    public function test_fresh_authenticated_body_does_not_require_another_sso_refresh(): void
    {
        Http::fake(['*' => Http::response([], 200, ['Cache-Control' => 'max-age=600'])]);
        $character = $this->character();
        $client = app(EsiHttpClient::class);
        $client->authenticatedResponse('get', '/characters/123/assets/', $character);
        $character->token_expires_at = now()->subMinute();
        $client->authenticatedResponse('get', '/characters/123/assets/', $character);
        Http::assertSentCount(1);
    }

    public function test_complete_pagination_metadata_preserves_oldest_validation_and_earliest_expiry(): void
    {
        $started = now()->timestamp;
        Http::fake(fn ($request) => Http::response([['item_id' => (int) $request['page']]], 200, ['X-Pages' => '2', 'Cache-Control' => 'max-age='.((int) $request['page'] === 1 ? '60' : '30')]));
        $client = app(EsiHttpClient::class);
        $character = $this->character();
        $endpoint = '/characters/123/assets/';
        $this->assertCount(2, $client->authenticatedPaginatedRequest($endpoint, $character));
        $this->assertSame($started + 30, $client->paginationMetadata($endpoint, $character)['source_expires_at']);
        $this->travel(10)->seconds();
        Cache::forget('esi.assets.123');
        $this->assertCount(2, $client->authenticatedPaginatedRequest($endpoint, $character));
        $this->assertSame($started, $client->paginationMetadata($endpoint, $character)['validated_at']);
        $this->assertSame($started + 30, $client->paginationMetadata($endpoint, $character)['source_expires_at']);
        Http::assertSentCount(2);
    }
}
