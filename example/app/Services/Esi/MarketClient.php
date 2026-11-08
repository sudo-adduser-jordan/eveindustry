<?php

namespace App\Services\Esi;

use App\Models\MarketHistory;
use App\Models\MarketOrder;
use App\Services\Esi\Diagnostics\EsiDiagnosticsContext;
use App\Services\EsiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Market ESI endpoints: prices, region orders, history, industry cost indices and market groups.
 */
class MarketClient
{
    public function __construct(
        protected EsiHttpClient $http,
    ) {}

    public function getMarketPrices(): ?array
    {
        $cacheKey = 'esi.market.prices';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('ESI market prices: returning cached data ('.count($cached).' items)');

            return $cached;
        }

        Log::info('ESI market prices: fetching from API');
        $start = microtime(true);

        $response = $this->http->publicRequest('get', '/markets/prices/');

        if ($response === null) {
            return null;
        }

        if ($response->successful()) {
            $result = $response->json();
            $elapsed = round(microtime(true) - $start, 2);
            $count = is_array($result) ? count($result) : 0;
            Log::info("ESI market prices: received {$count} items in {$elapsed}s");
        } else {
            $elapsed = round(microtime(true) - $start, 2);
            Log::warning("ESI market prices: HTTP {$response->status()} after {$elapsed}s");
            $result = null;
        }

        if ($result !== null) {
            Cache::put($cacheKey, $result, 86400);
        }

        return $result;
    }

    /**
     * Get all market orders for a region from the database.
     *
     * Orders are populated by region order sweeps (FetchRegionOrders: Jita
     * every 15-30 minutes, the other hubs hourly) in the market_orders table.
     *
     * Returns an array of order arrays matching the ESI response format so
     * existing consumers (MarketTradingService, etc.) work unchanged.
     */
    public function getMarketRegionAllOrders(int $regionId): array
    {
        try {

            // Read every order in the region.  lazyById pages through the
            // table in primary-key chunks so the Eloquent models stay small;
            // only the plain arrays below are accumulated.
            $orders = [];

            $query = MarketOrder::where('region_id', $regionId)
                ->select(['order_id', 'type_id', 'is_buy_order', 'price',
                    'volume_remain', 'volume_total', 'min_volume',
                    'location_id', 'duration', 'range', 'issued']);

            foreach ($query->lazyById(5000, 'order_id') as $o) {
                $orders[] = [
                    'order_id' => $o->order_id,
                    'type_id' => $o->type_id,
                    'is_buy_order' => $o->is_buy_order,
                    'price' => $o->price,
                    'volume_remain' => $o->volume_remain,
                    'volume_total' => $o->volume_total,
                    'min_volume' => $o->min_volume,
                    'location_id' => $o->location_id,
                    'duration' => $o->duration,
                    'range' => $o->range,
                    'issued' => Carbon::parse($o->issued),
                ];
            }

            if ($orders === []) {
                Log::debug("getMarketRegionAllOrders: no orders in DB for region {$regionId}");

                return [];
            }

            Log::debug('getMarketRegionAllOrders: returning '.count($orders)." orders from DB for region {$regionId}");

            return $orders;
        } catch (\Exception $e) {
            Log::error("getMarketRegionAllOrders: DB error for region {$regionId}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * The best price per type among a region's stored orders: the lowest sell
     * price, or the highest buy price when $buyOrders. One grouped query, so
     * a scan of a whole region needs no order arrays in memory.
     *
     * @return array<int, float> type_id => price
     */
    public function getRegionBestPrices(int $regionId, bool $buyOrders): array
    {
        try {
            return MarketOrder::where('region_id', $regionId)
                ->where('is_buy_order', $buyOrders)
                ->groupBy('type_id')
                ->selectRaw('type_id, '.($buyOrders ? 'MAX' : 'MIN').'(price) as best_price')
                ->pluck('best_price', 'type_id')
                ->map(fn ($price) => (float) $price)
                ->all();
        } catch (\Exception $e) {
            Log::error("getRegionBestPrices: DB error for region {$regionId}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Fetch one page of every order (buy and sell) in a region from ESI.
     *
     * Pass the ETag ESI returned for this page last time to get a 304
     * (RegionOrdersPage::NOT_MODIFIED) when the page has not changed. Goes
     * through EsiHttpClient, so the "market-order" token budget and the
     * global error budget apply. Never throws; the caller decides whether a
     * failed page is retried.
     */
    public function fetchRegionOrdersPage(int $regionId, int $page, ?string $etag = null): RegionOrdersPage
    {
        $endpoint = "/markets/{$regionId}/orders/";
        $headers = $etag !== null && $etag !== '' ? ['If-None-Match' => $etag] : [];

        try {
            $response = app(EsiDiagnosticsContext::class)->with(
                ['dataset' => 'market_orders', 'resource_id' => $regionId, 'page' => $page],
                fn () => $this->http->publicResponse('get', $endpoint, ['order_type' => 'all', 'page' => $page], $headers),
            )->response;
        } catch (EsiRequestException $e) {
            if (in_array($e->outcome, ['rate_limited', 'error_limited', 'coordination'], true)) {
                return new RegionOrdersPage(RegionOrdersPage::RATE_LIMITED, retryAfter: max(1, $e->retryAfterSeconds), httpStatus: $e->response?->status());
            }
            // This legacy market-page contract hands an explicit ETag back to
            // the sweep, which verifies that its persisted page body exists.
            if ($e->response === null || ($e->response->status() === 304 && ! $etag)) {
                return new RegionOrdersPage(RegionOrdersPage::FAILED);
            }
            $response = $e->response->response;
        }

        $pages = $response->header('X-Pages');
        $pages = $pages !== null && $pages !== '' ? max(1, (int) $pages) : null;
        $newEtag = $response->header('ETag') ?: null;

        if ($response->status() === 304) {
            return new RegionOrdersPage(RegionOrdersPage::NOT_MODIFIED, pages: $pages, etag: $newEtag ?? $etag, httpStatus: 304);
        }

        if ($response->status() === 404 && $page > 1) {
            return new RegionOrdersPage(RegionOrdersPage::MISSING, pages: $pages, httpStatus: 404);
        }

        if (! $response->successful()) {
            Log::warning("ESI region orders: HTTP {$response->status()} for region {$regionId} page {$page}");

            return new RegionOrdersPage(RegionOrdersPage::FAILED, httpStatus: $response->status());
        }

        $orders = $response->json();
        if (! is_array($orders)) {
            Log::warning("ESI region orders: unreadable body for region {$regionId} page {$page}");

            return new RegionOrdersPage(RegionOrdersPage::FAILED, httpStatus: $response->status());
        }

        return new RegionOrdersPage(
            RegionOrdersPage::OK,
            orders: array_values($orders),
            pages: $pages ?? 1,
            etag: $newEtag,
            httpStatus: $response->status(),
        );
    }

    public function getMarketRegionOrders(int $regionId, int $typeId, string $orderType = 'sell'): ?array
    {
        $cacheKey = "esi.market.orders.{$regionId}.{$typeId}.{$orderType}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug("ESI market orders region {$regionId} type {$typeId} ({$orderType}): returning cached data (".count($cached).' orders)');

            return $cached;
        }

        Log::info("ESI market orders: fetching {$orderType} orders for type {$typeId} in region {$regionId}");
        $start = microtime(true);

        $response = $this->http->publicRequest('get', "/markets/{$regionId}/orders/", [
            'type_id' => $typeId,
            'order_type' => $orderType,
        ]);

        if ($response === null) {
            return null;
        }

        if ($response->successful()) {
            $result = $response->json();
            $elapsed = round(microtime(true) - $start, 2);
            $count = is_array($result) ? count($result) : 0;
            Log::info("ESI market orders: received {$count} {$orderType} orders for type {$typeId} in region {$regionId} in {$elapsed}s");
        } else {
            $elapsed = round(microtime(true) - $start, 2);
            Log::warning("ESI market orders: HTTP {$response->status()} for region {$regionId}, type {$typeId} ({$orderType}) after {$elapsed}s");
            $result = null;
        }

        if ($result !== null) {
            Cache::put($cacheKey, $result, EsiService::REGION_ORDERS_CACHE_TTL);
        }

        return $result;
    }

    public function getMarketRegionHistory(int $regionId, int $typeId): ?array
    {
        try {
            $rows = MarketHistory::where('region_id', $regionId)
                ->where('type_id', $typeId)
                ->orderBy('date', 'desc')
                ->get(['date', 'average', 'highest', 'lowest', 'volume', 'order_count']);

            if ($rows->isNotEmpty()) {
                Log::debug("getMarketRegionHistory: returning {$rows->count()} entries from DB for region {$regionId} type {$typeId}");

                return $rows->map(fn (MarketHistory $row) => [
                    'date' => $row->date->toDateString(),
                    'average' => $row->average,
                    'highest' => $row->highest,
                    'lowest' => $row->lowest,
                    'volume' => $row->volume,
                    'order_count' => $row->order_count,
                ])->all();
            }

            Log::debug("getMarketRegionHistory: no DB data for region {$regionId} type {$typeId}, falling back to ESI");
        } catch (\Exception $e) {
            Log::error("getMarketRegionHistory: DB error for region {$regionId} type {$typeId}: {$e->getMessage()}");
        }

        // Fallback: fetch from ESI directly if no DB data is available yet
        // return $this->fetchMarketRegionHistoryFromEsi($regionId, $typeId);
        //  $result = null;
        return null;
    }

    /**
     * Fetch market history directly from ESI (used as fallback when DB has no data).
     */
    public function fetchMarketRegionHistoryFromEsi(int $regionId, int $typeId): ?array
    {
        $cacheKey = "esi.market.history.{$regionId}.{$typeId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::info("ESI market history: fetching for type {$typeId} in region {$regionId}");
        $start = microtime(true);

        $response = $this->http->publicRequest('get', "/markets/{$regionId}/history/", ['type_id' => $typeId]);

        if ($response === null) {
            return null;
        }

        if ($response->successful()) {
            $result = $response->json();
            $elapsed = round(microtime(true) - $start, 2);
            $count = is_array($result) ? count($result) : 0;
            Log::info("ESI market history: received {$count} entries for type {$typeId} in region {$regionId} in {$elapsed}s");
        } else {
            $elapsed = round(microtime(true) - $start, 2);
            Log::warning("ESI market history: HTTP {$response->status()} for region {$regionId}, type {$typeId} after {$elapsed}s");
            $result = null;
        }

        if ($result !== null) {
            Cache::put($cacheKey, $result, 21600);
        }

        return $result;
    }

    public function getIndustrySystemCostIndices(): ?array
    {
        return Cache::remember('esi.industry.systems', 21600, function () {
            $response = $this->http->publicRequest('get', '/industry/systems/');

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Fetch the list of all market group IDs from the ESI API.
     *
     * @return array<int>|null Array of market group IDs, or null on failure.
     */
    public function getMarketGroupIds(): ?array
    {
        return Cache::remember('esi.market_group_ids', EsiService::SDE_CACHE_TTL, function () {
            $response = $this->http->publicRequest('get', '/markets/groups/');

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Fetch details for a specific market group from the ESI API.
     *
     * Returns market group data including name, description, parent_group_id, and types.
     * Results are cached for 30 days (SDE data).
     */
    public function getMarketGroupInfo(int $marketGroupId): ?array
    {
        return Cache::remember("esi.market_group.{$marketGroupId}", EsiService::SDE_CACHE_TTL, function () use ($marketGroupId) {
            $response = $this->http->publicRequest('get', "/markets/groups/{$marketGroupId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }
}
