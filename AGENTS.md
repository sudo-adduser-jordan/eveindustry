# User Generated

## Tools
- never use sleep

## Ask

- Always use the ask feature when presenting options, choices, or decisions to the user.
- Never present options as plain text, markdown lists, or chat output when the ask tool is available.

## Comments

- No comments in source code.
- Do not create comments in the codebase.
- Documentation lives in the project's markdown files.

## Logging

Reference: https://loggingsucks.com/

- Default log paths under `./logs/`:
  - `./logs/test`
  - `./logs/errors`
- Use JSONL for log files.
- Pretty print logs.
- Color logs, keys, values, and value types.

## Task Automation

Reference: https://taskfile.dev/docs/reference/schema

- Do not use external scripts.
- Use default command set:
  - `install`
  - `update`
  - `remove`
  - `setup`
  - `migrate`
  - `start`
  - `stop`
  - `dev`

- add colored logging
- [command] content

## Commits

- never write descriptions
- use user defined commit message format
- `tag: statement of change`
```sh
add: 
update: 
remove: 
move: 
feature:
fix:
```
- if a tag does not exist in the above list, suggest one

- always commit to current branch
- never create a new branch unless asked
- do not push

# End User Generated




# AGENTS.md

Go (Echo + sqlx) API for EVE Online industrialists. Players sign in with
EVE SSO, link characters, and use planning and tracking tools built on their
ESI data plus public market data. The Go API serves `/api/v1` consumed by
`frontend/` (Vite, TypeScript).

Stack: Go 1.24+, Echo, sqlx (raw SQL, embedded SQLite via modernc.org/sqlite, no CGO).

Request flow: route → handler (`internal/http`, thin) → domain package
(`internal/<area>`, pure logic + tests) → `internal/store` (sqlx) → SQLite
(`DB_PATH`, default `storage/eveindustry.sqlite`), or `internal/esi` for live EVE calls.

## Layout (Go tree)

| Path | Contents |
|------|----------|
| `cmd/api` | API server (`GET /up`, `/api/v1/*`, JSON `{data, meta}` envelope) |
| `cmd/worker`, `cmd/scheduler`, `cmd/migrate` | Job runner, cron dispatcher, SQL migration runner |
| `internal/http` | Echo router + handlers (one file per route group) |
| `internal/store` | sqlx data layer; tables defined in `migrations/*.sql` |
| `internal/esi` | ESI transport (rate limiter, error circuit, token refresh, clients) |
| `internal/auth`, `internal/market`, `internal/calc`, `internal/industry`, `internal/pi` | Domain logic, all with unit tests |
| `frontend/` | React app (installable PWA, see `docs/pwa.md`; Vite, React 19, strict TypeScript, React Router, TanStack Query). Builds to `public/app` (gitignored), served at `/app/{path?}` behind auth. Its API client is typed from `docs/openapi.json`: after regenerating the spec run `npm run gen:api` and commit `src/api/schema.d.ts`. CI runs type check, build, Vitest and size-limit. |

Request flow: route → handler → domain package → sqlx store / SQLite / `internal/esi`. Background work (jobs phase) fills tables and cache keys that the request path reads.

### Feature → service map

| Area | Main services |
|------|---------------|
| Dashboard | `IndustryService`, `PlanetaryService` (reads cache warmed by `dashboard:refresh`) |
| Calculators | `ManufacturingCalculatorService`, `ReactionsCalculatorService`, `InventionPlannerService`, `ManufacturingBrowserService` (reads `manufacturing_costs`), `MarketService` |
| Build programs | `CorporationBuildProgramService`, `AssetService`, calculators |
| Industry | `IndustryService`, `ProductionService`, `ReprocessingService`, `IndustryStockService` |
| Assets | `AssetService`, `AbyssalService` |
| Planetary | `PlanetaryService`, `FactoryPlanetPlannerService` |
| Market and contracts | `MarketTradingService`, `MarketBrowserService`, `ContractAppraisalService` |
| Corporation | `CorporateDonationService`, `CorporationProjectsService`, `MoonScanService`, `EsiService` |
| Wormholes | `EsiService` |
| Admin | Admin users, sponsors and ESI diagnostics |
| Install and themes | `SetupStatusService`, `ThemeService`, command `app:install` |

Market prices for calculators come from `MarketService::getSellPrices()`: a 5th-percentile average of sell orders, read from `market_aggregates` (the region's last complete order sweep, cached per sweep) or from raw `market_orders` for a region never swept, falling back to `market_prices`. Region order sweeps are run by `MarketOrderSweepService` (see `docs/phase-c.md`). The five trade regions are in `MarketTradingService::REGIONS` (Jita, Amarr, Dodixie, Rens, Hek).

## External data sources

- EVE ESI (`esi.evetech.net`): character, corp, market and universe data. Always go through `EsiService`.
- CCP SDE JSONL zip (`developers.eveonline.com`): static data, via `SdeImportService`.
- Fuzzwork blueprint API: blueprint/reaction activities.
- everef.net: daily market history CSVs and public contract snapshots.

## Scheduled tasks

Dispatched by `cmd/scheduler` (one tick loop, run every minute). Times are UTC. Every entry has an overlap guard. The table below is the ported behavior; job names are the Go `internal/jobs` registrations.

| Command | Schedule | Status | What it does | Writes |
|---------|----------|--------|--------------|--------|
| `sde:import` | daily 02:00 | on | Downloads the SDE zip and imports systems, categories, groups, types, market groups, stations, stargates, type materials, blueprints. Skips if checksum unchanged. Inline. | `eve_types`, `sde_*`, `inv_*`, `type_materials`, `market_groups`, `blueprint_activities` |
| `market:update-prices` | daily 04:00 | on | ESI global prices. Inline. | `market_prices` |
| `esi:import-types` | weekly Sun 03:00 | on | Dispatches `ProcessEveTypeChunk` (`updates`). | `eve_types` |
| `sde:import-blueprints` | weekly Sun 05:00 | on | Fuzzwork data, dispatches `ProcessBlueprintDataChunk` (`updates`). | `blueprint_activities` |
| `esi:import-market-groups` | 1st and 15th 03:00 | on | ESI market group tree. Inline. | `market_groups` |
| `characters:refresh-data` | every 30 min | on | Staggered `RefreshCharacterProfile` jobs for recently active users and ESI-active characters. | `characters` |
| `dashboard:refresh` | every 15 min | on | `RefreshDashboardStats` per user active in 7 days, spread over 600 s. Cache TTL 1200 s. | cache `dashboard.*` |
| `industry:refresh` | every 30 min | on | `RefreshIndustryData` per active user. | cache (IndustryService) |
| `assets:refresh` | every 30 min | on | `RefreshAssetData` per user active in 7 days with an ESI-active character. | SQL owner snapshots (optional legacy cache dual write) |
| `market:import-daily-history` | every 3 h | on | `ImportDailyMarketHistory` (`updates`) for today and yesterday from everef. | `market_history` |
| `contracts:import` | hourly | on | `ImportPublicContracts` stages a generation and atomically publishes its pointer. | `public_contracts`, `public_contract_items` |
| `market:fetch-region-orders --region=10000002` | every 15 min, gated | on | Jita sweep: `FetchRegionOrders` → `FetchRegionOrderPages` batch → `CompleteMarketOrderSweep` (`market`). Runs every 15 min while the last complete sweep took < 10 min, else every 30 (`MarketOrderSweepService::isDue`). | `market_orders`, `market_order_sweeps`, `market_aggregates` |
| `market:fetch-region-orders --exclude=10000002` | hourly | on | Same sweep for Amarr, Dodixie, Rens and Hek. | same |
| `manufacturing:precompute-costs` | every 4 h | on | Safety net: `PrecomputeManufacturingCostsChunk` (`updates`) per trade region, ME 0–10. A completed sweep also queues it for its region (at most every 30 min). | `manufacturing_costs` |
| `esi:diagnostics-flush` | every minute | on, no-op unless `ESI_DIAGNOSTICS_ENABLED` | Queues `FlushEsiDiagnostics` (`default`): drains the `go_diag_stream` table in 200-event checkpointed transactions, bounded to 45 s; re-queues itself only while a backlog remains. Gaps are recorded in the checkpoint. | `esi_diagnostic_events`, `esi_endpoint_metrics`, `esi_budget_metrics`, `esi_telemetry_checkpoints` |
| `esi:diagnostics-prune` | daily 06:30 | on | Deletes expired diagnostics rows in 500-row batches (events 14 d, endpoint metrics 90 d, budget minute rows 7 d, hourly 90 d). | same |
| `demo:reset` | daily 03:30 | on, no-op unless `DEMO_ENABLED` | Regenerates the demo account with three new characters and warms its caches. See `docs/demo-mode.md`. | demo user rows, `characters`, asset snapshots |
| `notifications:dispatch` | every 5 min | on | Prunes `notification_log` (14 d), then queues `DispatchUserNotifications` (`default`) for active users with a push subscription; no-op until VAPID keys are set. See `docs/pwa.md`. | `notification_log`, `push_subscriptions` |
| `esi:refresh-tokens` | every 10 min | off | Tokens are refreshed on demand inside `EsiService` instead. | `characters` |
| `market:fetch-region-history` | every 6 h | off | `FetchRegionHistory` → `FetchRegionHistoryChunk`. Replaced by the everef import. | `market_history` |

## Queues and workers

- `cmd/worker` drains three SQLite queues (`go_jobs`): `default`, `updates` and `market`. Each job registration sets its own queue, `MaxTries` and `Timeout`.
- Per-user refresh jobs use `default`. Region order sweeps use `market`. Imports and manufacturing precompute use `updates`.
- SQLite has one file for everything: tables, the `go_jobs` queue, the `go_locks` overlap/unique locks, the `go_cache` shared cache and the `go_diag_stream` diagnostics buffer (WAL mode, 5 s busy timeout). Scheduler and worker share it across processes; `MemoryQueue` is tests-only.
- Sessions are stateless HMAC cookies (`eve_auth_session`, 120 min); no server storage. See `docs/operations/esi-scaling.md` for the legacy rollout notes.
- ESI-calling jobs check the error limiter and back off until the error window resets.

## ESI diagnostics

Passive, flag-gated capture of ESI traffic (`app/Services/Esi/Diagnostics`), buffered in the `go_diag_stream` table, drained to SQLite by `esi:diagnostics-flush`, reported at Admin → ESI Diagnostics (`/api/v1/admin/esi-diagnostics/*`). Any code that calls ESI outside `EsiHttpClient` must report its attempts through `EsiDiagnosticsRecorder`; wrap batch calls in `EsiDiagnosticsContext::with()` for dataset, page and batch fingerprint. Rollout, flags and interpretation: `docs/operations/esi-diagnostics.md`.

## Known issues

- Jita sweep timing has not been measured against real ESI; the Jita cadence drops to 30 min on its own when a sweep takes 10 min or more.
- `sde-import` overlaps with the `process-eve-type-chunk`, `process-market-group-chunk` and `process-blueprint-data-chunk` jobs, which write the same tables from other sources.

## Working rules

1. Put logic in a domain package; keep handlers thin. Add unit tests alongside the logic, and a handler test with stubbed stores (`internal/http`) for new endpoints.
2. Verify with `go build ./...`, `go test -count=1 ./...`, `go vet ./...`, and `gofmt -l .` (must print nothing).
3. All ESI calls go through the `internal/esi` clients so they are rate-limited, error-limit tracked and cached. Never call ESI directly from a handler or job.
4. New recurring work: register a job in `internal/jobs` with explicit queue, `MaxTries` and `Timeout`, and add a schedule entry in `cmd/scheduler` (with overlap guard). Limit per-user refreshes to users active in the last 7 days.
5. Bulk writes use chunked upserts; marshal JSON columns explicitly first.
6. Schema changes are new dated `migrations/*.up.sql`/`.down.sql` pairs on top of the squashed baseline; never edit the baseline or applied ones (`cmd/migrate` chain test enforces ordering and key consistency).
7. Work on a branch and merge to `master` by pull request.
8. Do not add AI attribution (no `Co-Authored-By: Claude`, no "Generated with Claude Code") to commit messages or PR descriptions.

## Open-source release additions

- **Licence:** AGPL-3.0-or-later, provided as is (`LICENSE`, `NOTICE`). The app shows a source link (`APP_SOURCE_URL`, exposed as `source_url` on `/api/v1/me`). Contributions are signed off (DCO). No tracking or analytics on by default, no account impersonation (a CI step fails if the word returns).
- **Logging:** slog JSON to stdout (see `cmd/api/main.go`).
- **Themes:** tokens only, no raw colours: `docs/styling.md`.
