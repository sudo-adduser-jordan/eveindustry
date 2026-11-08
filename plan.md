# Go port plan (Echo + sqlx)

Live status: branch `go-phase-1-reads`. Repo root is the Go project;
`example/` holds the untouched Laravel original as the behavior reference
(read-only; deleted at cutover). PHP stays authoritative until cutover
parity; then Blade views, legacy `/api` aliases and their tests are deleted
per the existing rule.

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
- Phase 4c (`070a5ec`): blueprints/industry-jobs fetch + the three
  stock endpoints.
- Phase 4d (`86a7336`): universe ESI (types, images, stations, systems,
  structures) + GET /industry/jobs overview.
- Phase 4e (`41ce284`): corp blueprints/assets ESI + GET /industry/can-produce.
- Phase 4f (`8a23c53`): blueprint + reaction-formula research pages.
- Phase 4g (`a9c87cd`): industry stock report (gate, live shortfalls).
- Phase 5a (`6b59c9d`): station-configs CRUD (first planning writes).
- Phase 5b (`b497847`): wholemap writes (signatures, connections,
  jump-logs, notes).
- Phase 5c (`42c2a68`): invention + reaction plan CRUD.
- Phase 5d (`01f70bf`): moon occupation write.
- Phase 5e (`387aff3`): personal build programs (chain nesting, merge, plans).
- Phase 5f (`0087044`): corp build programs, stock items, structures,
  industry refresh writes.
- Phase 5g: corporate build-program pages (overview, enable/disable, show).
- Phase 5h: progress calculator (calc.SummariseProgress) + personal/corp
  progress and asset-location endpoints.
- Phase 5i: heavy industry availability + reprocessing reads.
- Phase 5j: structures index (all activities + bonuses).
- Phase 5k: moon scans (TSV analyse, pricing modes, systems + system reprice).
- Phase 5l: wholemap reads (map, jump logs, live location).
- Phase 6a: asset browser (personal/corporate index, by-location, refresh).
- Phase 6b: asset materials, ships, stock-manager type search.
- Phase 6c: corporate donations (status, enable/disable, index).

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
      `cc17a74`), stock (done `070a5ec`).
- [ ] Industry group: jobs overview, can-produce, research, stock
      report (all done: `86a7336`, `41ce284`, `8a23c53`, `a9c87cd`);
      dashboard is jobs-phase territory (warmed caches).
- [ ] Auth phase (part 1 done `51ac980`: sessions, RequireAuth,
      demo-guard, /me, /logout, account delete): EVE SSO, character-scoped
      skills/stock, admin/stock-manager gates.
- [ ] ESI phase (core `a2eb801`, character client + skills `cc17a74`,
      blueprints/jobs + stock `070a5ec`; still needed: diagnostics,
      HTTP cache, demo responses, 4 remaining sub-clients, route-group
      table): live fallbacks, adjusted prices, cost indices.
- [ ] Writes: station-configs + wholemap + plans + moon + personal
      programs (done `6b59c9d`, `b497847`, `42c2a68`, `01f70bf`,
      `387aff3`), corp programs + stock items + structures + refresh
      (done `0087044`).
- [ ] Jobs phase: `cmd/worker` queue (17 jobs), `cmd/scheduler` tick loop,
      Horizon replacement decision (riverqueue/asynq).
- [ ] Migrations phase: 74 Laravel migrations -> `migrations/*.up/down.sql`,
      `cmd/migrate` on golang-migrate, freeze Laravel side at cutover.
- [ ] Deploy phase: new Dockerfile/compose (deleted files), smoke test
      (`/up`, `/api/v1/health`, `/api/v1/me` -> 401).
- [ ] Cutover: traffic shift, delete Blade views + legacy `/api` aliases +
      `tests/Feature/LegacyApi` tests, regen `docs/openapi.json` + `npm run gen:api`.
