<?php

namespace Tests\Unit;

use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\EsiRequestException;
use App\Services\EsiRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array']);
        Cache::flush();
    }

    public function test_spec_groups_share_budgets_for_blueprints_industry_and_planets(): void
    {
        $client = app(EsiHttpClient::class);
        foreach (['/characters/1/blueprints/', '/characters/1/industry/jobs/', '/characters/1/planets/'] as $path) {
            $this->assertSame('char-industry', $client->resolveRateLimitGroup($path));
        }
        $this->assertSame('corp-asset', $client->resolveRateLimitGroup('/corporations/1/assets/'));
        $this->assertSame('char-asset', $client->resolveRateLimitGroup('/characters/1/assets/names/', 'post'));
        $this->assertSame('market-order', $client->resolveRateLimitGroup('https://esi.evetech.net/latest/markets/10000002/orders/?page=2'));
        $this->assertSame('legacy', $client->resolveRateLimitGroup('/universe/types/34/'));
        $this->assertSame(['window' => 900, 'limit' => 1800], app(EsiRateLimiter::class)->getLimitConfig('char-asset'));
    }

    public function test_reservation_accounts_for_maximum_cost_then_reconciles_actual_response(): void
    {
        $limiter = app(EsiRateLimiter::class);
        $id = $limiter->reserve('char-asset', '1');
        $this->assertSame(1795, $limiter->remaining('char-asset', '1'));
        Http::fake(['*' => Http::response([], 200)]);
        $limiter->reconcile('char-asset', '1', $id, Http::get('https://example.test'));
        $this->assertSame(1798, $limiter->remaining('char-asset', '1'));
        $this->assertSame(1800, $limiter->remaining('char-asset', '2'));
        $this->assertSame(600, $limiter->remaining('char-industry', '1'));
    }

    public function test_window_returns_tokens_after_expiry(): void
    {
        $limiter = app(EsiRateLimiter::class);
        for ($i = 0; $i < 3; $i++) {
            $this->assertNotNull($limiter->reserve('char-notification', '1'));
        }
        $this->assertNull($limiter->reserve('char-notification', '1'));
        $this->travel(901)->seconds();
        $this->assertNotNull($limiter->reserve('char-notification', '1'));
    }

    public function test_429_without_group_headers_enforces_absolute_retry_after_for_bucket_only(): void
    {
        $limiter = app(EsiRateLimiter::class);
        $id = $limiter->reserve('char-asset', '1');
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '40'])]);
        $limiter->reconcile('char-asset', '1', $id, Http::get('https://example.test'));
        $this->assertNull($limiter->reserve('char-asset', '1'));
        $this->assertNotNull($limiter->reserve('char-asset', '2'));
        $this->travel(15)->seconds();
        $this->assertSame(25, $limiter->resetsInSeconds('char-asset', '1'));
        $this->travel(26)->seconds();
        $this->assertNotNull($limiter->reserve('char-asset', '1'));
    }

    public function test_remote_remaining_and_limit_headers_constrain_local_budget(): void
    {
        $limiter = app(EsiRateLimiter::class);
        $id = $limiter->reserve('char-asset', '1');
        Http::fake(['*' => Http::response([], 200, ['X-Ratelimit-Remaining' => '4', 'X-Ratelimit-Limit' => '100/15m', 'X-Ratelimit-Used' => '2'])]);
        $limiter->reconcile('char-asset', '1', $id, Http::get('https://example.test'));
        $this->assertSame(4, $limiter->remaining('char-asset', '1'));
        $this->assertNull($limiter->reserve('char-asset', '1'));
        $this->assertSame(100, $limiter->getLimitConfig('char-asset')['limit']);
    }

    public function test_coordination_failure_defers_instead_of_failing_open(): void
    {
        config(['esi.coordination_store' => 'unconfigured-store']);
        $this->expectException(EsiRequestException::class);
        app(EsiRateLimiter::class)->reserve('char-asset', '1');
    }

    public function test_first_remote_header_keeps_other_in_flight_reservations_reserved(): void
    {
        $limiter = app(EsiRateLimiter::class);
        $first = $limiter->reserve('char-asset', '1');
        $second = $limiter->reserve('char-asset', '1');
        Http::fakeSequence()->push([], 200, ['X-Ratelimit-Remaining' => '6'])->push([], 200, ['X-Ratelimit-Remaining' => '4']);
        $limiter->reconcile('char-asset', '1', $first, Http::get('https://example.test'));
        $this->assertSame(1, $limiter->remaining('char-asset', '1'));
        $this->assertNull($limiter->reserve('char-asset', '1'));
        $limiter->reconcile('char-asset', '1', $second, Http::get('https://example.test'));
        $this->assertSame(4, $limiter->remaining('char-asset', '1'));
    }

    public function test_production_coordination_uses_non_evicting_default_redis_connection(): void
    {
        $this->assertSame('default', config('cache.stores.esi_coordination.connection'));
        $this->assertSame('default', config('cache.stores.esi_coordination.lock_connection'));
    }
}
