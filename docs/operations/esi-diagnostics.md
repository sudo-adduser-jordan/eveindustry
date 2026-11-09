# ESI diagnostics rollout

> Go port note: the buffer is the `go_diag_stream` SQLite table (no Redis),
> drained by the same `esi:diagnostics-flush` cadence. `ESI_DIAGNOSTICS_SINK`
> is ignored; only `ESI_DIAGNOSTICS_ENABLED` gates capture. The rollout
> detail below describes the legacy PHP/Horizon deployment.

Passive capture of the application's own ESI traffic, reported at **Admin → ESI Diagnostics** (`/app/admin/esi-diagnostics`). It never sends an ESI request. Everything is off until the flags below are set, so deploying the code changes nothing.

## What runs where

| Piece | Where | Notes |
|---|---|---|
| Capture | `EsiHttpClient::send()`, `EsiHttpCache`, circuit open/close, `FetchRegionHistoryChunk` (direct HTTP) | One event per wire attempt. Cache hits and local deferrals are separate event kinds. |
| Buffer | Redis stream `esi:diag:stream` on the **non-evicting** Redis (`default` connection) | Capped by entries (`ESI_DIAGNOSTICS_MAX_ENTRIES`, default 100,000). The cap is not a byte limit. |
| Collector | `esi:diagnostics-flush` every minute → `FlushEsiDiagnostics` on `default` | 200-event checkpointed transactions, at most 45 s per run. No new workers. |
| Retention | `esi:diagnostics-prune` daily 06:30 | Events 14 d, endpoint metrics 90 d, budget minute rows 7 d, budget hourly rows 90 d. |
| Storage | `esi_diagnostic_events`, `esi_endpoint_metrics`, `esi_budget_metrics`, `esi_telemetry_checkpoints` | Failures only are stored per event; successes are aggregated. |

## Flags

| Variable | Default | Meaning |
|---|---|---|
| `ESI_DIAGNOSTICS_ENABLED` | `false` | Master switch for capture. |
| `ESI_DIAGNOSTICS_SINK` | `null` | `redis` to buffer on the stream. With `null`, events are discarded. |
| `ESI_DIAGNOSTICS_CAPTURE_CACHE` | `true` | Cache hit/miss/revalidation counters. The highest-volume kind; turn off first if overhead matters. |
| `ESI_DIAGNOSTICS_CAPTURE_DEFERRALS` | `true` | Local deferral counters. |
| `ESI_DIAGNOSTICS_MAX_ENTRIES` | `100000` | Stream entry cap. |
| `ESI_DIAGNOSTICS_REDIS_CONNECTION` | `default` | Must be the non-evicting instance. |
| `ESI_DIAGNOSTICS_EVENT_DAYS` / `_ENDPOINT_DAYS` | `14` / `90` | Retention. |
| `APP_RELEASE` | unset | Commit or tag stamped on failure events; drives the deployment markers. Set it in the deploy. |

## Enabling (delivery step 4)

1. Deploy and run migrations (`php artisan migrate`). Restart Horizon so workers load the new jobs and listeners.
2. Set `ESI_DIAGNOSTICS_SINK=redis`, `ESI_DIAGNOSTICS_ENABLED=true` and `APP_RELEASE`, then reload config and restart Horizon.
3. Within two minutes the admin page's **Capture health** should read *healthy* with a recent last ingestion. If it says *collector has not flushed*, check the scheduler and Horizon.
4. For 24–48 hours watch, on the same page and in Redis:
   - backlog and oldest event age stay near zero (the collector keeps up);
   - *Lost before SQL* and *Collection gaps* stay at zero;
   - Redis memory: `MEMORY USAGE esi:diag:stream` at the observed length gives bytes per entry. Multiply by the cap and confirm it fits the instance with headroom before leaving the cap at 100,000. Lower `ESI_DIAGNOSTICS_MAX_ENTRIES` if not.
   - MySQL: row counts and bytes per row of the four tables. Estimate detail storage as `failures per day × 14 × measured bytes per row`, plus aggregates and indexes.
5. Tune the cap and retention from those numbers.

## Rollback

Set `ESI_DIAGNOSTICS_ENABLED=false`; capture stops immediately and nothing else depends on it. The collector keeps draining any backlog while the sink is `redis`; to drop the buffer, `DEL esi:diag:stream esi:diag:stream:epoch esi:diag:stream:drops`. The tables can stay; retention will expire them. Migrations are reversible (`down()` drops the four tables).

## Reading the data

- Rates use real upstream attempts. A cache hit or a local deferral is never counted as an HTTP failure.
- A *protection pause* is the application stopping at 20 remaining legacy errors. It is not proof the upstream count reached zero. Pause ends are inferred when the pause expires.
- A repeated batch fingerprint identifies a recurring batch of IDs, not which item is invalid.
- Missing budget headers show as gaps, never as zero.
- Unhealthy or off capture is shown as incomplete data. A quiet chart in that state means nothing.
- Loss counters: *Lost before SQL* is captures that never reached MySQL; *Expired by retention* is rows deliberately pruned.
- Cap eviction of unread entries is only detectable on Redis 7+ (`entries-added`). Older Redis still reports stream resets and counted drops.

## After a week (delivery step 5)

Rank the highest-volume avoidable error families on the page and plan targeted mitigations (bounded negative caching, permission or membership refresh, invalid-ID handling, custom-name batch isolation, timing). Compare errors per 1,000 requests and dataset freshness before and after each change using the release markers. Nothing here removes scopes, deactivates characters or suppresses requests automatically.
