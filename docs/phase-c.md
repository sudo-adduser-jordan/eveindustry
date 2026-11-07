# Phase C: fresh market data and a deployable split

What Phase C changed, why, and where it differs from the plan. AGENTS.md
holds the current schedule and queue tables.

## Region order sweeps (market:fetch-region-orders)

Turned back on, after fixing why it was off.

**What was wrong.** `FetchRegionOrderPages` called ESI with `Http` directly
(no rate limiter, no ETag). A page that failed returned quietly, so the batch
counted it as a success, and the batch's `then()` cleanup deleted every
order not tagged with the new batch id, which included every order on the
failed page. B5 only changed the read side (`getMarketRegionAllOrders`
returns every row) and added a test that every page is fetched; the jobs
were unchanged.

**How a sweep works now** (`MarketOrderSweepService`, table
`market_order_sweeps`):

1. `FetchRegionOrders` (queue `market`) notes the start time, fetches page 1
   through `EsiService::fetchRegionOrdersPage()` → `MarketClient`
   (`EsiHttpClient`: "markets" rate-limit group, ESI error budget), then
   starts a sweep row. If the region already has a running sweep it stops
   (one stuck for 30 minutes is marked `abandoned`). Page 1 is stored here,
   so it is no longer fetched twice.
2. Pages 2..N run as a batch of `FetchRegionOrderPages` (queue `market`).
   Each page sends `If-None-Match` with the ETag from the last sweep, cached
   together with that page's order ids (`market.orders.page.{region}.{page}`,
   2 h). A 200 upserts the page; a 304 stamps the cached order ids as seen
   without rewriting them; a 404 past the end counts as an empty page.
   Every order seen gets `updated_at = now`.
3. A page that fails throws, so the queue retries it (`backoff` 10/30/60 s,
   `maxExceptions` 3). Our rate limiter refusing, or the ESI error budget
   running low, releases the job instead (not counted as a failure,
   `tries` 10). The batch does not allow failures: the first page that fails
   for good cancels the rest.
4. The batch's `finally()` queues `CompleteMarketOrderSweep`. With no failed
   page and no cancellation it deletes the region's orders with
   `updated_at` before the sweep started (in 5,000-row chunks), rebuilds
   `market_aggregates`, publishes the snapshot, records the duration and
   queues precompute for the region. Otherwise the sweep is `failed` and
   **nothing is deleted**.

Sweep duration, pages (and how many were 304), orders seen and deleted are
on each `market_order_sweeps` row. `php artisan market:sweep-status` prints
the latest sweep per hub and the current Jita cadence.

## Queues

| Queue | Supervisor | Connection (retry_after) | Jobs | Production processes |
|---|---|---|---|---|
| `default` | supervisor-1 | `redis` (240 s) | per-user refreshes | 1-2 |
| `market` | supervisor-3 | `redis-market` (420 s) | `FetchRegionOrders`, `FetchRegionOrderPages` (60 s), `CompleteMarketOrderSweep` (300 s) | 1-2 |
| `updates` | supervisor-2 | `redis-long` (960 s) | imports, `PrecomputeManufacturingCostsChunk` (600 s) | 1 |

At most 5 processes in production (local: 2 + 2 + 1). `JobTimeoutTest`
checks the ceiling, that the three queues exist, that the sweep jobs run on
`market` and that precompute stays on `updates`.

## Cadence

- Jita (The Forge): a 15-minute schedule entry gated by
  `MarketOrderSweepService::isDue()`. It runs every 15 minutes while the last
  completed Jita sweep took under 10 minutes, otherwise (and until a sweep
  has been measured) only at :00 and :30. Data is therefore at most about 30
  minutes old plus one sweep.
- Amarr, Dodixie, Rens, Hek: hourly (`--exclude=10000002`).
- Not measured against real ESI. Our own limiter allows 300 "markets"
  requests per 5 minutes, so a ~380-page Forge sweep needs roughly 6.5
  minutes of request budget on its own, and the hourly hub sweeps share
  that budget. Check `market:sweep-status` after the first day; if Jita
  stays above 10 minutes it simply runs every 30.

## market_aggregates

One row per (region, type) from the latest completed sweep: best bid, best
ask, `sell_price_p5` (the cheapest-5%-by-volume average that
`getSellPrices()` returns), buy/sell volume and order counts, `sweep_id` and
`snapshot_at` (the sweep's start). Only the latest snapshot is kept (one row
per type, upserted), not one set per sweep: Jita every 15 minutes would add
over a million rows a day. Types that left the market are deleted.

`market_orders` stays as raw staging and is not truncated; unseen orders are
removed only after a complete sweep.

**Readers.**
- `MarketService::getSellPrices()` / `getSellPricesWithSource()` read
  `sell_price_p5` once the region has a completed sweep; the 5th-percentile
  maths is shared (`MarketService::percentileAverage()`), so the value is the
  same as before. Per-type prices are cached in Redis under
  `market.aggregates.p5.{region}.{sweep_id}.{type}` (2 h); a new sweep
  changes the key, so nothing is flushed. Regions never swept, and calls
  with a non-default window or percentile, still read raw `market_orders`.
  All existing pricing tests pass unchanged.
- `MarketFreshnessService`: `orders_as_of` is the start of the last
  completed sweep (fallback: `MAX(market_orders.updated_at)` for regions
  never swept). Orders are stale after 2 h (was 3).
- Left on raw orders: station trading / arbitrage (`MarketTradingService`)
  and `GET /market/orders` need individual orders or per-station prices,
  which the region aggregate does not hold.

## Manufacturing precompute

- A completed sweep queues `PrecomputeManufacturingCostsChunk` for its region
  (T1, as the cron does by default), at most once per 30 minutes per region,
  so 15-minute Jita sweeps do not queue more work than the single `updates`
  worker can run. The 4-hour `manufacturing:precompute-costs` cron stays as a
  safety net.
- `manufacturing_costs.prices_as_of` is the snapshot the prices came from
  (null before the region's first completed sweep).
- After a run that wrote at least one row, the region's rows that run did not
  rewrite are deleted. Note: a manual `--include-t2` (or `--include-all`) run
  is undone by the next default T1 run, which removes the T2 rows.

## Deviations and open points

- Sessions now live in the evicting Redis, as planned. Under memory pressure
  the least recently used sessions can be evicted (users signed out). Raise
  `REDIS_CACHE_MAXMEMORY` or point `SESSION_CONNECTION` back at `default` if
  that matters more than the memory bound.
- `meta.freshness.source` keeps the value `market_orders` when prices come
  from aggregates (they are built from the orders), so the API contract does
  not change.
- If ESI's 5-minute cache rolls over during a long sweep, pages can come from
  two snapshots; an order that moved pages may be missed and removed until
  the next sweep re-adds it. Not handled.
- Precompute trigger is throttled (30 min per region), a small deviation
  from "every completed sweep".
