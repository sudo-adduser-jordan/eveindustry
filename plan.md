# Go port plan (Echo + sqlx)

Live status: branch `go-phase-1-reads`, 31 endpoints, ~7.5k lines Go.
PHP stays authoritative until cutover parity; then Blade views, legacy
`/api` aliases and their tests are deleted per the existing rule.

## Stack

- Echo router, sqlx + raw SQL (MySQL), stdlib only otherwise.
- Envelope `{data, meta}` / `{message, code, errors?}` mirrors
  `ApiResponse`; 422s mirror FormRequest rules; pagination
  `{page, per_page, total, last_page}` mirrors `paginatedArray`.
- No ORM, no codegen yet. Live ESI/SSO/queue/migrate arrive in their phases;
  until then endpoints serve local tables and document each skip inline.

## Done

- Phase 3a (`51ac980`): session auth — signed cookies, RequireAuth +
  demo-guard on `/api/v1`, real `/me`, `/logout`, account delete.
- Phase 3b (`532e568`): corporate-export availability.
- Phase 4a (`a2eb801`): ESI transport core (outcomes, circuit, rate
  limiter, token refresher, HTTP client).
- Phase 4b (`cc17a74`): ESI character client (credentials, pagination,
  assets, names) + calculator skills endpoints.

- Phase 0 (`2335d80`): skeleton — `/up`, `/api/v1/health` (DB+cache probes,
  200/503), `/api/v1/me` 401 stub, `cmd/migrate` placeholder.
- Phase 1 (`b375707`): reference basics — types, market-groups (+tree,
  contents), systems, stations; market prices/history/orders/freshness.
- Phase 1b (`85fa72d`): blueprints, reaction formulas, invention chains,
  manufacturing categories, corporations (empty pre-auth). Searches 422 on
  bad `q` per `SearchRequest`.
- Phase 1c (`883c84e`): station-trading + region-arbitrage scanners.
- Phase 2a (`9af30fc`): manufacturing calc + queue-summary.
- Phase 2b (`dc0ec03`): reactions calc.
- Phase 2c (`8ede948`): invention calc + decryptors list.
- Phase 2d (`c57be5e`): contract appraisal + contract browser.
- Phase 2e (`a91a92e`): reaction planner (chain, pricing, fees).
- Phase 2f (`7f0c017`): reaction browser (profitability + sell scores).
- Phase 2g (`6bad334`): manufacturing browser (live + precomputed).
- Phase 2h (`31df73e`): factory planner (all five tiers, chains, tax).
- Each phase has a `gofmt` follow-up; `build` + `vet` + `test -count=1` green.

## Conventions / known deviations

- No auth yet: data routes are public in Go (PHP needs `auth:sanctum`).
  Auth phase puts them behind session middleware.
- No ESI client: ESI search fallbacks, Fuzzwork fallback, regional price
  seeding, adjusted prices, cost indices skipped; fee blocks compute to zero
  with `has_data` preserved. Error paths that mirror ESI-null (history miss)
  include the candidate with volume 0.
- Unknown calculator/material edge: float `quantity_per_run` serializes as
  `90` not `90.0` (numerically equal); `quantity_per_run` uses ceil float.
- Unknown `/api/v1/*` paths 401 for anonymous callers (Echo runs group
  middleware on its not-found route); PHP 404s before auth. Authenticated
  callers get the JSON 404 in both.
- `api/v1/appraisals` in the demo allow-list is the legacy path, so demo
  users are blocked from `POST /market/appraisals` — faithful to PHP.
- Aggregates always scope to `market_region_states.active_sweep_id`.
- Freshness `source` vocabulary differs from price `source`
  (`market_orders`/`market_prices`/`none` vs `orders`/`fallback`/`none`).

## Remaining

- [ ] Calculators: corporate-export (done `532e568`), skills (done
      `cc17a74`), stock needs live asset aggregation → ESI phase.
- [ ] Industry group (`routes/api_v1/industry.php`), planetary, heavy
      (moon scans, structures, wholemap), corp tools, programs + planning
      writes, admin, notifications, session/characters/me.
- [ ] Auth phase (part 1 done `51ac980`: sessions, RequireAuth,
      demo-guard, /me, /logout, account delete): EVE SSO, character-scoped
      skills/stock, admin/stock-manager gates.
- [ ] ESI phase (core done `a2eb801`; still needed: diagnostics,
      HTTP cache, demo responses, 8 resource sub-clients, character token
      store, route-group table): live fallbacks, adjusted prices, cost
      indices, skills/stock endpoints.
- [ ] Jobs phase: `cmd/worker` queue (17 jobs), `cmd/scheduler` tick loop,
      Horizon replacement decision (riverqueue/asynq).
- [ ] Migrations phase: 74 Laravel migrations -> `migrations/*.up/down.sql`,
      `cmd/migrate` on golang-migrate, freeze Laravel side at cutover.
- [ ] Deploy phase: new Dockerfile/compose (deleted files), smoke test
      (`/up`, `/api/v1/health`, `/api/v1/me` -> 401).
- [ ] Cutover: traffic shift, delete Blade views + legacy `/api` aliases +
      `tests/Feature/LegacyApi` tests, regen `docs/openapi.json` + `npm run gen:api`.
