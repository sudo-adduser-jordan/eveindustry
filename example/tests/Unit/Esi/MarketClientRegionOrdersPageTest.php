<?php

namespace Tests\Unit\Esi;

use App\Services\Esi\MarketClient;
use App\Services\Esi\RegionOrdersPage;
use App\Services\EsiRateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MarketClient::fetchRegionOrdersPage(): one page of a region's orders,
 * through EsiHttpClient, with ETag support and a status for every outcome.
 */
class MarketClientRegionOrdersPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    private function client(): MarketClient
    {
        return app(MarketClient::class);
    }

    public function test_ok_page_returns_orders_page_count_and_etag(): void
    {
        Http::fake(['*/markets/10000002/orders/*' => Http::response(
            [['order_id' => 1, 'type_id' => 34]],
            200,
            ['X-Pages' => '42', 'ETag' => '"abc"'],
        )]);

        $page = $this->client()->fetchRegionOrdersPage(10000002, 3);

        $this->assertSame(RegionOrdersPage::OK, $page->status);
        $this->assertSame([['order_id' => 1, 'type_id' => 34]], $page->orders);
        $this->assertSame(42, $page->pages);
        $this->assertSame('"abc"', $page->etag);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === ['order_type' => 'all', 'page' => '3']
                && ! $request->hasHeader('If-None-Match');
        });
    }

    public function test_sends_the_etag_and_reports_not_modified_on_304(): void
    {
        Http::fake(['*' => Http::response('', 304, ['ETag' => '"abc"', 'X-Pages' => '42'])]);

        $page = $this->client()->fetchRegionOrdersPage(10000002, 2, '"abc"');

        $this->assertSame(RegionOrdersPage::NOT_MODIFIED, $page->status);
        $this->assertTrue($page->isSuccessful());
        $this->assertSame([], $page->orders);
        Http::assertSent(fn (Request $request) => $request->header('If-None-Match') === ['"abc"']);
    }

    public function test_404_past_the_first_page_is_a_missing_page_but_failed_on_page_one(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Requested page does not exist!'], 404)]);

        $this->assertSame(RegionOrdersPage::MISSING, $this->client()->fetchRegionOrdersPage(10000002, 9)->status);
        $this->assertSame(RegionOrdersPage::FAILED, $this->client()->fetchRegionOrdersPage(10000002, 1)->status);
    }

    public function test_server_errors_fail_the_page(): void
    {
        Http::fake(['*' => Http::response(null, 502)]);

        $page = $this->client()->fetchRegionOrdersPage(10000002, 1);

        $this->assertSame(RegionOrdersPage::FAILED, $page->status);
        $this->assertSame(502, $page->httpStatus);
        $this->assertFalse($page->isSuccessful());
    }

    public function test_tracks_the_esi_error_budget(): void
    {
        Http::fake(['*' => Http::response(null, 502, ['X-Esi-Error-Limit-Remain' => '5', 'X-Esi-Error-Limit-Reset' => '30'])]);

        $this->client()->fetchRegionOrdersPage(10000002, 1);

        $this->assertTrue(app(\App\Services\EsiService::class)->isEsiErrorLimited());
    }

    public function test_our_rate_limiter_refusing_is_reported_as_rate_limited_without_a_request(): void
    {
        $limiter = $this->mock(EsiRateLimiter::class);
        $limiter->shouldReceive('store')->andReturn(Cache::store('array'));
        $limiter->shouldReceive('reserve')->with('market-order', null)->andReturnNull();
        $limiter->shouldReceive('resetsInSeconds')->with('market-order', null, 5)->andReturn(12);
        Http::fake();

        $page = $this->client()->fetchRegionOrdersPage(10000002, 1);

        $this->assertSame(RegionOrdersPage::RATE_LIMITED, $page->status);
        $this->assertSame(12, $page->retryAfter);
        Http::assertNothingSent();
    }

    public function test_upstream_429_honours_retry_after_without_group_headers(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '17'])]);
        $page = $this->client()->fetchRegionOrdersPage(10000002, 1);
        $this->assertSame(RegionOrdersPage::RATE_LIMITED, $page->status);
        $this->assertSame(429, $page->httpStatus);
        $this->assertSame(17, $page->retryAfter);
    }

    public function test_upstream_420_and_shared_circuit_report_counting_down_retry_deadline(): void
    {
        $this->freezeTime();
        Http::fake(['*' => Http::response([], 420, ['X-Esi-Error-Limit-Reset' => '40'])]);
        $page = $this->client()->fetchRegionOrdersPage(10000002, 1);
        $this->assertSame(RegionOrdersPage::RATE_LIMITED, $page->status);
        $this->assertSame(40, $page->retryAfter);
        $this->travel(10)->seconds();
        $this->assertSame(30, $this->client()->fetchRegionOrdersPage(10000002, 2)->retryAfter);
        Http::assertSentCount(1);
    }
}
