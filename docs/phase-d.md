# Phase D: React

The React app lives in `frontend/` and is served by Laravel from the same
origin under `/app`, so there is no CORS and the session cookie is the login.

## Done in the first slice

- Vite, React 19, strict TypeScript, React Router, TanStack Query.
- Typed API client from `docs/openapi.json` (`openapi-fetch`;
  `npm run gen:api` writes `src/api/schema.d.ts`, keep it in sync).
  Writes get the Sanctum CSRF cookie flow; a 401 sends the user to `/login`.
- Shell with navigation (ported pages plus links to the classic Blade pages),
  `FreshnessBadge` (amber when stale, "no data yet" instead of a price of 0),
  and three pages: Overview, Characters, Market data.
- `GET /app/{path?}` (`SpaController`, `auth`) serves `public/app/index.html`
  for every client route; 503 with a hint when the app is not built.
- `GET /api/v1/health` (public, throttled 60/min): database and cache checks,
  200 or 503. nginx no longer lists
  directories (`try_files $uri /index.php`) so `/app/` reaches Laravel.
- Type check, build, Vitest, size-limit
  (120 kB gzip shell, 60 kB per route chunk; shell is about 85 kB today).

## Second slice: assets

- `/app/assets` and `/app/assets/corporation` replace the Blade Character and
  Corporation Assets pages: summary tiles, search (`?search=` in the URL),
  solar systems open and stations collapsed, ship and container contents
  behind a toggle, and a Refresh button that polls every 15 s (for up to
  10 min) until `meta.last_updated` moves past the click.
- New v1 endpoints: `GET /assets/by-location` and
  `GET /corporation/assets/by-location` (grouped by system and station, not
  paginated, as the Blade page was) and `POST /assets/refresh` (202, queues
  the forced `RefreshAssetData`; throttled to 6 a minute).
- The corporation assets 403 now says which check failed
  (`AssetService::corporationAssetsDenial()`), and the page shows it.
- Removed: `assets/index`, `assets/corporation`, `_grouped` and `_sort_header`
  views, `GET /api/assets` and `GET /api/assets/corporation` and their legacy
  test. `/assets` and `/assets/corporation` (route names kept) redirect to
  the app. Materials, ships and abyssal stay on Blade for now.

## Third slice: industry slots and reprocessing

- `/app/industry/slots` replaces the Blade Industry Slots page: totals,
  per-character slot meters, skills and running jobs, jobs ready for
  collection, Refresh (`POST /industry/availability/refresh`) and Clear
  caches (`POST /industry/availability/clear`; the 429 message is shown).
- `/app/industry/reprocessing` replaces Reprocessing Value: totals, the
  prices' freshness badge, and per-character, per-location item tables.
- `ScopeWarning` (from `/me` scopes) replaces the Blade `_scope-warning`
  partial on ported pages: it warns about a scope no character has granted.
- The v1 `industry/availability` and `industry/reprocessing` resources are
  now fully typed (sub-resources instead of `unknown[]`), and availability
  gains `ready_for_collection`. `getReadyForCollectionJobsForUser()` no
  longer widens its window by a minute per job (it called `addSeconds()` on
  a mutable `now()` inside the loop).
- Removed: `industry/availability` and `industry/reprocessing` views,
  `ReprocessingController`, the four `/api/industry/availability|reprocessing`
  aliases and their legacy tests. The old URLs redirect to the app.
- Page tests seed the TanStack Query cache (`src/test/render.tsx`), so a
  whole page renders without any request.

## Fourth slice: materials, ships and abyssal items

- `/app/assets/materials`, `/app/assets/ships` and `/app/assets/abyssal`
  replace the last three Blade asset pages. Each has the same "Updated … ago"
  status and Refresh button as the asset lists (`useAssetRefresh` and
  `RefreshBar` in `components/AssetRefresh.tsx`; the query polls through its
  `refetchInterval` until `meta.last_updated` passes the click).
- New v1 endpoints: `GET /assets/materials` (with
  `meta.corporation_assets_warning`, the Blade page's banner), `GET /assets/ships`
  and `GET /assets/abyssal`. Abyssal attributes carry the display strings
  (`value`, `change`) formatted by `AbyssalService`, so the unit tables stay in
  PHP. Materials with no price show "no price" instead of 0.
- Removed: the three views and their `AssetController` actions. The old URLs
  redirect to the app (route names kept). Only the `assets.refresh` POST is
  left on the Blade controller, for the pages still on Blade.
- The abyssal endpoint asks ESI about every module on a cold cache, so the
  page can take a while on first load.

## Fifth slice: station trading and region arbitrage

- `/app/market/station-trading` and `/app/market/region-arbitrage` replace
  the two Blade scanners. They use the v1 endpoints as they were (typed
  already), so there is no API change.
- The form keeps its parameters in the URL (`?region_id=...&min_margin=...`),
  so a scan can be shared or reloaded. Submitting applies them; the page
  scans with the defaults on first load, which is cheap now that scans read
  the stored order sweeps instead of calling ESI.
- Results are fetched 200 to a page (the v1 maximum) and sorted by daily ISK
  profit by the server. The table sorts and filters the loaded page by item
  name in the browser; the Blade page's `limit` is replaced by Previous/Next.
- Freshness badges show how old the orders are (both regions on arbitrage).
- Shared pieces for later scanners: `ScanForm` (+ `useScanParams`),
  `OpportunityTable` and `Pager`.
- Removed: the two views, `MarketTradingController`, the two
  `/api/market/...` aliases and their legacy tests (the shared
  `MarketFixtures` trait stays, v1 tests use it). The old URLs redirect to
  the app.

## Sixth slice: admin area

- `/app/admin/dashboard`, `/users`, `/users/:user/industry-jobs`, `/sponsors`
  (+ `/sponsors/new`, `/sponsors/:id`), `/settings` and `/discord-bot` replace
  the Blade admin pages. All sit under `AdminGuard`: it reads `admin` from
  `/api/v1/me` and shows an "Admin access required" page to everyone else.
  The API is the real check, and a 403 from any admin call shows the same
  page (`AdminError`). The sidebar lists the admin links only for admins.
- New `/api/v1/admin/*` endpoints (`routes/api_v1/admin.php`, `admin`
  middleware, which answers 403 JSON for `api/*` instead of redirecting):
  dashboard, users (list with search, sort and pagination; toggle status,
  admin and stock manager; delete), a user's industry jobs
  (valued at a hub's sell prices, `UserJobsOverviewService`), sponsors (CRUD,
  multipart; updates use `POST` because PHP does not parse multipart `PUT`),
  the admin's own account (email and password) and the Discord bot settings.
  Super admins still cannot be changed or deleted (403).
- The Discord token is never returned: the resource has `has_bot_token`, and
  the form keeps the stored token when it is left empty (`clear_bot_token`
  removes it). This differs from the Blade form, which printed the token into
  the page.
- The dashboard charts are plain SVG bars (`BarChart`), so Chart.js and its
  CDN script are gone from this area.
- Removed: the admin views, `SponsorController`, `AdminSettingsController`,
  `DiscordBotSettingsController` and `AdminController`, plus the Blade-era tests. The old
  `/admin/...` GET URLs redirect to the app and keep their route names
  (`admin.dashboard`, `admin.users`, `admin.sponsors.index`, ...); the POST,
  PUT and DELETE routes are gone. `/admin/login` stays on Blade.
- The user's own `industry.jobs-overview` view still has an unused
  "viewing another user" branch; it goes when `/industry/jobs` is ported.
## Sixth slice, part two: moon scan and wholemap

- `/app/tools/moon-scan` replaces the Blade Moon Scan Analyser: paste a scan,
  pick the market hub and value basis, graded moon cards (ore bars by tier, top
  three highlighted), "hide occupied moons", the stored-system loader with
  "re-analyse as" and the occupying-corporation picker (corporation search,
  set and clear). Prices carry a freshness badge. The ore bars are plain CSS.
- `/app/wholemap` replaces the Blade Wholemap: stats, the chain of
  connections (click a node to filter to that system), signatures with type
  filter, lock, link, edit and delete, connections with jump logs, system notes,
  location history, and tracking (polls `GET /wholemap/location` every 15 s,
  follows the character, and offers to link the previous system's open wormhole
  signature when entering J-space). Pasting scanner rows anywhere on the page
  imports them into the tracked or filtered system. Dialogs use the native
  `<dialog>` element (`components/Modal`); the "graph" is the same flat chain
  as before, so no graph library. Tracking, the tracked character and the
  history stay in `localStorage` as before.
- New v1 endpoint: `GET /wholemap?system_id=` (signatures with their
  connection, the whole chain, notes, systems mapped; the last 24 hours, via
  `WholemapMapService`). The Blade page read this server-side. The rest of
  the API was already there; the moon, signature, connection, note and
  jump-log resources now declare their types (ids as integers, nullable text)
  and the write endpoints document their response resources, so the client
  is fully typed.
- Removed: both views, `MoonScanController`, `WholemapController`, all 19
  `/api/tools/moon-scan*` and `/api/wholemap/*` aliases and their legacy
  tests (`tests/Feature/WholemapTest.php` covered the Blade page and the old
  routes; the v1 tests cover the same behaviour). The old URLs redirect to the
  app (route names kept; `?system_id=` is not carried over).
## Sixth slice: planetary dashboard and factory planner

- `/app/planetary` replaces the Blade PI dashboard: totals, alerts, output per
  hour (a bar per product instead of the Chart.js doughnut and bar charts, so
  no chart library), factory capacity, and the per-character colony cards with
  extractor timers, factories, launchpad/storage fill and the resource filter.
  Refresh drops the cached colonies and reads ESI again.
- `/app/planetary/factory-planner` replaces the Blade planner: tier tabs,
  region, PI tax rate (debounced; the server applies the tax, the Blade page
  re-rendered stale figures), expandable input and profitability breakdowns for
  both row shapes (single-step tiers and the P1/P0 to P3 single-planet chains).
- New v1 endpoints: `GET /planetary` and `POST /planetary/refresh` (204,
  6 a minute). The factory planner already had `GET /calculators/factory-planner`;
  its `results` rows are untyped in the OpenAPI spec (two shapes), so the page
  declares the row types itself.
- Removed: both views, `PlanetaryController`, `FactoryPlanetPlannerController`,
  `GET /api/planetary/factory-planner/calculate` and its legacy test and golden
  test (the v1 test compares with the recorded golden file). `/planetary` and
  `/planetary/factory-planner` redirect to the app (route names kept).
## Calculators, part 1: manufacturing and the two browsers

- Golden snapshots first: `tests/Feature/Calculators/GoldenSnapshotTest.php`
  pins the whole `/api/v1` body of every calculator (manufacturing with fees,
  reaction-system-only, buyback, a second queue item, queue summary,
  reactions, reaction planner and browser, manufacturing browser and browse,
  invention) for fixed seeded inputs, against files in
  `tests/Feature/Calculators/golden`, plus explicit headline numbers. Re-record
  only for an intended change (`RECORD_CALCULATOR_FIXTURES=1`). The ports
  below were checked against them and against the Blade pages on real data.
- `/app/calculators/manufacturing`, `/app/calculators/manufacturing-browser`
  and `/app/calculators/reaction-browser` replace the three Blade pages. The
  calculation stays in PHP: the pages post to `/calculators/manufacturing`,
  `/calculators/manufacturing/queue-summary`, `/calculators/manufacturing-browser`
  and `/calculators/reaction-browser` and only format what comes back.
- Manufacturing keeps the queue (add, re-price on region or buyback change,
  remove, clear), combined materials with stock, Multibuy and CSV exports and
  the personal and corporate build program exports. The structure bonuses
  the Blade page computed in JavaScript now come from the server:
  `GET /structures` rows carry `effective_bonuses` (`me`, `te`; reactions get
  the rig bonus only). Reaction structures come from
  `GET /structures?activity_type=reaction`.
- New: `GET /calculators/corporate-export` says whether the corporate build
  program buttons apply (feature on for the corporation and a Director, CEO
  or Factory Manager character), as the Blade controller decided before.
- The v1 calculator resources are fully typed in the OpenAPI spec (the
  manufacturing job with its components, the queue summary, both browser
  rows) instead of `unknown[]`; build program responses now document `id`
  and the `201` responses are no longer typed as the number 201.
- Shared pieces for part 2: `components/calculators/` (`SearchPicker`,
  `StructureSelect`, `SystemPicker`, `MarketGroupTree`, `ProfitTable`,
  `ExportProgramButton`, formatting helpers) and `api/calculators.ts`.
- Removed: the three views, `ReactionBrowserController`, the browse and
  calculate actions of `ManufacturingBrowserController`, and the calculate,
  queue-summary, stock, character-skills and index actions of
  `ManufacturingController` with their `/api` aliases and legacy tests (the
  buyback, browse and queue-summary feature tests now call `/api/v1`). The
  old URLs redirect to the app. The reference lookups (blueprint search,
  blueprint data, type info, categories) and the station-config aliases stay
  until part 2 and the planning slice.
## Corporation tools (D6)

- `/app/corporate/donations`, `/projects`, `/projects/contributors`,
  `/build-programs`, `/build-programs/:program`, `/ess-tracker` and
  `/structure-vulnerability` replace the seven Blade pages. Each shows the
  server's 403 message (`ErrorNotice`) when a gate fails: no corporation, no
  projects scope, donation tracking off.
- New v1 endpoints, all in `routes/api_v1/corp.php`:
  - Donations: `GET /corporation/donations/status` (open to everyone, so the
    page can offer to enable tracking), `POST .../enable`, `.../disable`
    (enabler only, else 403), `.../refresh` (204).
  - Projects: `GET /corporation/projects/contributors` (leaderboard with
    `meta.summary`), `POST /corporation/projects/refresh`.
  - ESS: `POST /corporation/ess`, `PUT|DELETE /corporation/ess/{system}`,
    `POST /corporation/ess/{system}/payouts`. The cycle maths moved from the
    old controller into `EssTrackerService`. The update form sends the next
    payout time only when filled in (the Blade form always reset it).
  - Structure windows: `GET|POST /corporation/structure-vulnerability`,
    `PUT|DELETE .../{window}` (new gate `corporation.view-structure-timers`).
  - Build programs: `GET /corporation/build-programs` (feature status,
    eligible directors, programs; never 403), `GET .../{program}` (typed step
    tree and material volumes), `POST .../enable` and `.../disable`.
    Rename, delete, merge, location filter, progress and asset locations were
    already in v1.
- The corporation gates now deny with a message (`CorporationPolicy`).
- Typed what used to be `unknown[]`: donations, project contributors,
  `BuildProgramProgressResource` (steps, blueprints, materials, reactions).
  Recursive `children` stop at the first level in the spec; the page casts
  them (`asSteps`).
- The detail page keeps the Blade behaviour: input/output hangar filters (saved
  for managers), sections of merged programs, expandable steps and materials,
  Buy all / Buy missing Multibuy copy, reactions summary.
- Removed: the seven views, four controllers, every `/api/corporate/...` alias
  except `POST /api/corporate/build-programs` and `.../from-reaction-plan`
  (the Blade calculators still save corporate programs through them; remove
  them with the calculators), the two `search-systems` aliases, their legacy
  tests and the web-form tests for ESS and structure windows (replaced by v1
  tests). The old page URLs redirect to the app (route names kept).
## Sixth slice (industry): dashboard, jobs, tools, stock manager, build programs

- `/app/dashboard`, `/app/industry/jobs`, `/app/industry/can-produce`,
  `/app/industry/blueprint-research`, `/app/industry/reaction-formula-research`,
  `/app/industry/stock-manager`, `/app/build-programs` and
  `/app/build-programs/:id` replace the Blade pages. Old URLs redirect (route
  names kept; `build-programs.show` redirects to the program's id).
- New v1 read endpoints in `routes/api_v1/industry.php`: `GET /dashboard`,
  `/industry/jobs?region_id=` (values manufacturing and reaction output at a
  hub's sell price; an unpriced item is `null`, never 0), `/industry/can-produce`
  (cached an hour, refreshed by the existing `POST .../refresh`),
  `/industry/blueprint-research` (paginated, 100 a page in the UI),
  `/industry/reaction-formula-research`, `/stock` and `POST /stock/refresh`
  (stock managers only; the page shows a 403 message otherwise),
  `/build-programs` (summaries) and `/build-programs/{program}` (typed steps,
  `meta.volumes`). Dashboard and jobs logic moved into `DashboardService` and
  `JobsOverviewService`.
- `BuildProgramProgressResource` is now fully typed (`ProgressStepResource`,
  `ProgressSupplyResource`, `ProgressReactionResource`, ...). Sub-steps stay a
  recursive `children` list; the frontend narrows them with a local type.
- The program page reads the step tree from `GET /build-programs/{id}` and
  overlays `.../progress` by position. The location filter is saved with
  `PATCH .../location-filter` and applied to progress immediately.
- CSV exports are unchanged (`/industry/blueprint-research/export`,
  `/industry/reaction-formula-research/export`); the page links to them with
  the current search, filter and sort.
- Removed: the Blade views for these pages, `CanProduceController`,
  `IndustryStockController`, `DashboardController`, the research `index`
  actions, the old build program `index/show/update/destroy/merge/progress/
  asset-locations/location-filter` actions, their `/api` aliases and the
  matching legacy tests. Tests that used the aliases (progress, location
  filter, merge, reflection tests of the old controller's private helpers)
  now call `/api/v1` or `BuildProgramProgressCalculator`.
- Kept for later slices: `POST /api/build-programs` and
  `/api/build-programs/from-reaction-plan` (the Blade planners still call
  them) and the admin "view user's industry jobs" page
  (`industry/jobs-overview.blade.php`, `IndustryController@adminJobsOverview`).
- The dashboard's character list is read-only; set main and remove still live
  on the Blade `/characters` page ("Manage characters" links there).
## Market and tools slice: market browser, contracts, structures

- `/app/market/browser` is new as a page: the market tree used to exist only
  as a card inside the Blade Manufacturing browser. It browses root groups,
  subgroups and items (`?group=`) and searches item names (`?q=`), all on
  existing v1 endpoints (`/market-groups`, `/market-groups/{id}`, `/types`).
- `/app/tools/contract-appraisal` replaces the Blade appraisal tool
  (`POST /market/appraisals`, no API change). Lines with no matching contract
  say "no contracts" instead of a price of 0; the contract import's freshness
  badge shows above the result.
- `/app/tools/contract-browser` and `/app/tools/contract-browser/:contractId`
  replace the two Blade contract pages. New v1 endpoints `GET /market/contracts`
  (filters `type`, `search`; `meta.stats` counts every contract by type;
  `meta.freshness`) and `GET /market/contracts/{contractId}` (item lines with
  type names resolved). The filters live in the URL.
- `/app/structures` replaces My Structures: add, edit, delete and make-default
  on the v1 write endpoints, a solar system picker on `GET /systems?q=`, and
  reaction structures limited to refineries. `GET /structures` now carries
  `solar_system_name`, and `solar_system_id` is typed as an integer (it came
  out as a string in the spec).
- `ContractDetailResource` repeats the list fields instead of extending
  `ContractResource`: Scramble cannot read `parent::toArray() + [...]` and
  typed the resource as `unknown[]`.
- Removed: the contract and structures views, `ContractAppraisalController`,
  `ContractBrowserController`, `MyStructuresController`, their `/api` aliases
  (`/api/structures*`, `/api/tools/contract-appraisal`) and legacy tests. The
  old page URLs redirect to the app (route names kept).
- Kept: the four `/api/market-browser/*` aliases and `MarketBrowserController`,
  because the Blade Manufacturing browser still fetches the tree from them.
  Delete them with that page.

## Calculators, part 2: reactions, reaction planner, invention planner

- `/app/calculators/reactions`, `/app/calculators/reaction-planner` and
  `/app/calculators/invention-planner` replace the last three Blade
  calculators. They reuse the part 1 pieces and add `api/reactions.ts` and
  `api/invention.ts`. The calculation stays in PHP; checked against the Blade
  pages on real data (Caesarium Cadmide reaction and plan, Wolf invention).
- Reaction planner: saved plans (save, update, load, delete), cycle presets,
  per-entry slots and runs, raw materials with your stock and what is left to
  buy, formula ownership badges (needs the blueprints scope), Multibuy and
  the personal and corporate build program exports (from the reaction plan).
- Invention planner: saved plans, decryptor choice, skills loaded from a
  character (matched by skill id as before), the full result sections and the
  personal and corporate exports. The live "Output BPC" preview of the Blade
  page is gone: it repeated the decryptor maths in JavaScript, so the BPC is
  shown with the result instead. The decryptor list comes from the new
  `GET /calculators/invention/decryptors`.
- The reaction and invention results, the saved plan resources and the stock
  and skills endpoints are typed in the OpenAPI spec. The calculator
  resource `ReactionPlanResource` is renamed `ReactionPlanCalculationResource`
  because it collided with the saved plan resource of the same name.
- Removed: the three views, their controllers, every `/api/calculators/...`
  alias except the station configs and the factory planner (the
  reference lookups of both calculators and the plan routes go with them),
  and their legacy tests. Behaviour that only the old tests covered (plan
  defaults, stock aggregation, the datacore shopping list) now runs against
  `/api/v1`. The ESI fallback of the old reaction planner system search is
  not in v1 (SDE only, as documented). The old URLs redirect to the app.
- Left for the build programs slice: the `/api/build-programs/from-reaction-plan`
  and corporate aliases, which only the old reaction planner called.

## Local development

```bash
cd frontend && npm install && npm run dev   # proxies /api, /sanctum, /login to LARAVEL_URL (default localhost:8000)
npm run build                               # writes public/app (not in git)
```

## Final cutover

All feature areas now use React, including the admin area. `/` redirects to
`/app/dashboard`; the public landing page and sponsor banners remain at
`/welcome`. Existing page bookmarks keep their redirects. `/characters`
now redirects to the complete character management page, with add/renew,
set-main, remove, cached location/training and account deletion controls.

The shared shell provides logout.
Character changes use owner-checked v1 writes; main changes are transactional
and the last character cannot be removed. Account deletion keeps the token
revocation and session invalidation behaviour of the original form.

The last unused `/api` aliases (market tree, station configs and personal /
corporate program creation) and their controllers and legacy tests are gone.
Station-config behaviour tests now call v1. Historical sections above record
which aliases were temporarily kept while neighbouring pages were still Blade.

Bootstrap and Bootstrap Icons are removed. The original look (Orbitron/Rajdhani
fonts, glow background, chamfered panels, cyan buttons) is restored as plain CSS:
`frontend/src/styles/theme.css` for the app and `public/css/auth.css` for the
Blade pages. `theme.css` owns buttons (`.button`, `.primary`, `.danger`,
`.small`), form controls and dropdown rows (`.option`), so new pages should use
those classes instead of adding per-page control rules. Login, admin login, EVE scope review and the public landing page
use the local `public/css/auth.css` and native HTML controls. The configured
Google Analytics integration remains consent-gated. EVE images and sponsor
images keep their existing sources. The React navigation collapses on mobile.

Bundle limits remain 120 kB gzip for the shell and 60 kB for each lazy chunk;
the size check now evaluates chunks individually rather than summing them.
