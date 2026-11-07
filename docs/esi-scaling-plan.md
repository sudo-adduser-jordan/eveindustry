# EVE Industrialist: ESI, SQL and caching review

Reviewed 24 October 2026. The initial review used repository commit `9f6151ae04dc3efbc6001ab9d2f14a3d0935e4b0`. Implementation now lives on `codex/esi-scaling`, based on V2 commit `d72c3253560e26d6e66184fb1aa73c69d70241f9`. The findings below describe the reviewed baseline, followed by implementation status. Production application code and databases have not been changed.

## Implementation status — 24 October 2026

The three delegated workstreams have been integrated into one V2-based review branch. The supplied figures remain the pre-migration baseline; local tests do not replace production measurements.

| Finding / workstream | Implemented behavior | Remaining acceptance work |
|---|---|---|
| F1–F3 pagination and assets | Typed failures; complete page validation; corporation cursor traversal; successful empty inventories; atomic per-owner SQL snapshot publication and previous-generation retention. | Validate actual largest personal and corporation inventories and page counts. |
| F4–F7 transport and credentials | Specification-based group/token accounting, atomic non-evicting coordination, shared 420 circuit, 429 cooldowns, HTTP cache validators/freshness, explicit timeouts, identifiable User-Agent, single-flight rotating token refresh, transient failure classification and structure-cache age checks. | Observe live headers, budget minima and retry behavior through staged rollout. |
| F8–F9 scheduling | Consistent recent-user/active-character/scope gates, corporation role/membership suppression, staggered profile and user refresh dispatch, execution-time eligibility, overlap/uniqueness guards and typed retry deferrals. | Asset/industry jobs still orchestrate several owners within a user job. Finish per-owner scheduling/fairness if the observed 36-character account approaches its runtime budget; affiliation batching is also outstanding. |
| F10 durable reads | SQL snapshot generations survive Redis eviction; API filters and paging execute in SQL; location summaries and on-demand child pages avoid delivering the full inventory; materials aggregate in SQL; ship filtering precedes payload decoding; ownership/access changes invalidate projections. Legacy per-user Redis dual writes default off. | Measure end-to-end peak memory and latency against the largest real inventory. Legacy compatibility endpoints can still request full nested trees. |
| F11–F12 SQL publication | Market aggregate and public-contract generations publish through durable pointers; stale generations are fenced; bounded retention preserves active/recent snapshots. | Measure additional disk and migration duration; investigate abandoned building/staging generations after hard termination. |
| Manufacturing failures | Ordered 40-row upserts, bounded deadlock retries, region exclusion and bounded SQL-free terminal diagnostics. | Confirm production deadlock rate and failed-job growth decline. |
| Security and operations | Compatible encrypted-token reader, opt-in encryption backfill, persistent session connection, opt-in failed-job retention, rollout/rollback notes. | Stage token encryption only after every reader is upgraded; preserve APP_KEY and backups. |

Draft implementation: PR #233 in the original repository, targeting `v2`.

Validation completed locally: 1,528 PHP tests (7,777 assertions), 55 MySQL tests (250 assertions), two real-Redis tests (37 assertions), and 147 frontend tests pass. The full PHP suite passes (two opt-in Redis tests skipped there and run separately); actual Redis concurrency checks pass; focused MySQL 8 migration/publication/access/retention tests pass; frontend tests, TypeScript, production build and bundle limits pass. OpenAPI and frontend types were regenerated. The PHP suite retains three deprecations and one pre-existing test with no assertions; OpenAPI export retains resource-inference warnings.

Not yet proven: sustained production freshness at 2,000 active characters, the required hardware size, peak memory for the largest owner, and production queue/service latency. Application metrics, realistic workload replay, broader SDE import coordination and a full operational dashboard remain later plan work. Use the existing five-worker ceiling for the initial cohort and expand through measured stages; do not reactivate all inactive characters or infer capacity from passing regression tests.

## Recommendation

Keep Laravel, MySQL and Redis. The immediate work is to make synchronisation complete, recoverable and independent of page requests. More workers alone would amplify existing correctness and retry problems.

For more than 2,000 characters, use background jobs per character/dataset and per corporation/dataset, durable SQL snapshots, shared HTTP caching, and an ESI limiter driven by the specification and response headers. Treat character count, account distribution, asset rows, distinct corporations, PI colonies and project contributors as separate capacity dimensions.

The reviewed checkout has a useful foundation: ESI has a service boundary and specialised clients, SDE data is available locally, market page fetching uses ETags and batches, bulk upserts and several appropriate composite indexes exist, and queues/locks are separated from evictable cache Redis. Extend these patterns. The production statistics supplied below are explicitly pre-migration: features and migrations in the checkout must not be assumed to be deployed.

## Measured pre-migration baseline

The user supplied these SQL results on 24 October 2026 as the baseline for this plan. Population counts are exact query results; table row estimates and allocation figures come from InnoDB metadata. No post-migration performance is claimed.

| Population metric | Observed value | Planning consequence |
|---|---:|---|
| Users / enabled users | 1,285 / 1,285 | Enabled alone is not a useful recent-activity filter. |
| Enabled users logged in within 7 / 30 days | 59 / 253 | Separate recent, returning and dormant cohorts. Login recency is not concurrent usage. |
| Users with linked characters | 1,225 | Do not assume every enabled user owns a character. |
| Linked characters | 2,704 | Already above 2,000 registered characters; this is not the current refresh load. |
| ESI-active / inactive characters | 117 / 2,587 | 95.7% are marked inactive; cause and credential validity are unknown. |
| Eligible users / ESI-active characters in the 7-day cohort | 26 / 117 | All currently ESI-active characters fall within this recent-user cohort. |
| All linked characters on those 26 users | 133 | 16 linked characters are marked inactive; owner jobs must explicitly filter them. |
| Mean linked characters per eligible user | 5.12 | Mean ESI-active characters per eligible user is 117 / 26 = 4.50. |
| Largest eligible account | 36 linked characters | Include this observed account size in fairness and job-timeout tests; its active subset is unknown. |
| Corporations represented by ESI-active characters | 34 | This is not a count of authorised corporation collectors. |
| ESI-active characters without a refresh token | 0 | Token presence does not establish validity. |
| ESI-active characters needing access-token refresh | 115 | 98.3% of the active cohort; expiry is normal. Smooth refreshes and coordinate them per character. |

Thirty-three of the 59 recently logged-in users have no ESI-active character. Keep their user-created plans accessible without scheduling credential-based refreshes. Do not classify all 2,587 inactive characters as revoked or outage victims: the current evidence does not establish the reason. A return/reactivation flow must validate eligibility and enqueue a bounded backfill.

| Storage baseline | Estimated rows | Reported data + indexes |
|---|---:|---:|
| All listed tables | — | 4,052.60 MiB / 3.96 GiB |
| `failed_jobs` | 36,535 | 2,375.27 MiB; 58.6% of reported allocation |
| All other listed tables combined | — | 1,677.33 MiB / 1.64 GiB |
| `market_history` | 3,682,376 | 809.72 MiB, including 474.33 MiB of indexes |
| `market_orders` | 895,244 | 549.03 MiB, including 255.69 MiB of indexes |
| `manufacturing_costs` | 252,251 | 84.63 MiB |
| `public_contract_items` / `public_contracts` | 373,417 / 50,456 | 71.16 / 16.05 MiB |
| `structure_character_access` / `player_owned_structures` | 176,280 / 4,138 | 30.69 / 1.38 MiB |
| `characters` | 1,980 estimated; 2,704 exact | 12.56 MiB |

The estimated/exact character-count difference illustrates why table metadata is not a workload count. The market tables are the largest non-failure SQL allocations. Do not multiply shared regional market ingestion by character growth. Structure access pairs are material enough to test batching, denial expiry and cleanup, but these counts do not establish how many denials are genuine.

`market_order_sweeps` and `market_aggregates` are absent from this pre-migration table list, as expected for the baseline. Do not require historical sweep timing that this deployment did not record; start that measurement when the relevant implementation is deployed. Index presence also needs deployment verification; definitions in the checkout are not proof of deployed indexes.

### Failed-job baseline and revised priorities

The later grouped failed-job query returned 52,872 recorded failures, with 506 in the last seven days. These are terminal failure records, not request counts, attempts, queue depth or a failure percentage. They may overlap the earlier snapshot in time, but the metadata estimate of 36,535 must not replace this grouped count.

| Job / queue | Recorded failures | Last 7 days | Payload + exception MiB |
|---|---:|---:|---:|
| `FetchRegionHistoryChunk`, default + updates | 27,868 | 0 | 134.82 |
| `RefreshIndustryData`, default | 7,055 | 153 | 46.86 |
| `RefreshAssetData`, default | 6,372 | 188 | 37.05 |
| `FetchRegionOrderPages`, default + updates | 4,952 | 0 | 35.17 |
| `PrecomputeManufacturingCostsChunk`, default | 3,452 | 164 | 4,630.46 |
| `RefreshDashboardStats`, default | 2,245 | 1 | 12.11 |
| Other listed job/queue groups | 928 | 0 | 5.81 |
| Total | 52,872 | 506 | 4,902.28 |

Precompute accounts for approximately 94.5% of measured payload/exception bytes, averaging 1.34 MiB per failure. Diagnose payload size and exception length separately; avoid embedding large data arrays in queued jobs or exception messages. This does not prove which component is responsible. All 506 recent failures in this result are on `default`: assets 188, industry 153, precompute 164 and dashboard 1. Current failure records place precompute on the same queue as user refreshes, whereas the reviewed checkout intends it to use `updates`; validate routing and restart long-running workers as part of rollout. Historic queue assignments alone do not establish today's configuration for every producer.

The older history-fetch failures stopped in March and are a retention issue, not evidence of a current history-fetch incident. Capture representative diagnostics, classify actionable failures, then schedule bounded pruning of obsolete failure records. Do not replay the historical backlog indiscriminately. Horizon metadata retention and SQL `failed_jobs` pruning are separate concerns.

The 4,902.28 MiB of logical payload/exception bytes and 2,375.27 MiB of reported table allocation are different measurements and do not reconcile directly from these outputs. Check collection times, table statistics and storage/compression details before claiming disk reclaim. Deleting rows does not guarantee immediate filesystem space reclamation; no automatic table rebuild is proposed.

## Production evidence supplied after the review

The supplied 24 October logs show asset requests receiving HTTP 420 at 01:45:58–59, including error-budget remaining zero and a reported reset of one second. Multiple character IDs are affected, with repeated entries for one character. This confirms production error limiting, rather than merely a theoretical scale risk. It does not identify which earlier errors exhausted the budget, prove the upstream identity scope, or establish whether repeated calls came from duplicate jobs or another path. The one-second reset describes that response's window, not a safe fixed retry interval.

At 01:53:24 a failed expired-token refresh caused character `2124710409` to be deactivated. This confirms the deactivation code path is executing; the log does not reveal whether the refresh failed because of revoked credentials, SSO/network trouble or another cause. Do not infer that the preceding ESI 420 caused an SSO failure: these are different services.

### Additional evidence from supplied `backup.log`

The supplied file contains 10,000 physical lines, parsed as 9,178 timestamped events spanning 24 October 02:17:16–02:41:45 (24 minutes 29 seconds, using timestamps as logged). Multiline response bodies and the exception stack are continuations, not separate requests. Counts below are log events within this excerpt, not a complete traffic denominator or unique job executions.

| Event | Count | Meaning |
|---|---:|---|
| Authenticated HTTP 403 | 1,707 | 1,496 report missing required roles; 211 report the character is not in the corporation. |
| Authenticated HTTP 420 | 796 | Confirms widespread error-limit responses in this excerpt. |
| Authenticated HTTP 401 | 1 | Logged with a token-refresh retry attempt. |
| Characters represented across authenticated HTTP failures | 1,478 distinct | Much broader than the 117-character SQL-active snapshot; filters and snapshot timing must be reconciled. |
| Failed expired-token refresh / deactivation messages | 1,011 events; 210 distinct characters | Repeated processing of the same characters; does not establish permanent credential invalidity or 1,011 state transitions. |
| Local public-request limiter messages | 2,876 | All name the local `universe` group. These are suppressed local attempts, not 2,876 upstream 429 responses. |
| Industry queue terminal error | 1 | `RefreshIndustryData` exceeded maximum attempts. The excerpt does not identify its original exception/cause. |

Corporation industry-job endpoints feature prominently: one corporation endpoint records 226 failures with missing-role responses. The excerpt also logs character refresh completion with `updated=117,total=117`, while other work reaches a much broader set of character IDs. This is consistent with per-user services processing linked characters beyond the character command's active cohort; confirm dispatch/run identity and collection timing rather than assuming all those characters were active simultaneously. It strengthens F8 and the requirement to enforce the same owner/scope eligibility at dispatch and execution time, including already queued work.

The role/membership failures are an observed, preventable source of error traffic and plausible contributors to budget exhaustion; the log alone cannot attribute every 420 to them or establish the upstream limit identity. A character's ownership by an app user does not prove they belong to the requested corporation or hold its required role. Refresh affiliation/role evidence, choose one authorised corporation collector per dataset, and invalidate that grant on explicit membership/role denial. Suppress repeated ineligible calls rather than attempting them through every linked character. Keep general role-verification policy aligned with the endpoint's actual specification; do not assume the assets Director/CEO check is sufficient or necessary for every corporation dataset.

Revised first implementation gate: repeated known missing-role or wrong-corporation calls stop until access is reverified; ineligible/inactive linked characters cannot be reintroduced by cached model collections or old queued jobs; local cooldown suppression does not become a tight retry/logging loop; transient SSO errors cannot deactivate credentials. Add explicit tests for a corporation change, missing role, 36-character account, stale queued work and overlapping refreshes. Classify deactivation reasons before attempting recovery of the 210 characters in the excerpt or the 2,587 inactive population.

### Expanded `backup.log`: same end time, wider historical window

The replacement file expands the excerpt to 50,000 physical log lines (plus a trailing newline), parsed as 45,018 timestamped events from 00:13:53 to 02:41:45 on 24 October: 2 hours 27 minutes 52 seconds. It overlaps and contains the previously analysed ending window; these totals supersede the smaller excerpt for aggregate reporting and must not be added to it. Larger totals alone do not establish a worsening rate or post-migration behaviour.

| Expanded-window evidence | Count |
|---|---:|
| Authenticated HTTP 403 | 8,392: 7,273 missing-role, 1,109 wrong-corporation, 10 structure-forbidden |
| Authenticated HTTP 420 | 3,714 |
| Authenticated HTTP 401 | 5; all explicitly missing `esi-industry.read_corporation_jobs.v1` |
| Distinct characters across failed authenticated calls | 2,014 |
| Expired-token-refresh deactivation messages | 4,787 across the same 210 distinct characters |
| Local public `universe` suppression messages | 14,495 |
| Logged manufacturing-cost MySQL deadlock errors | 11, between 00:14:36 and 00:17:20 |
| Maximum-attempts errors | 2: manufacturing precompute and industry refresh |
| Character refresh completion messages | 5, each reporting 117 of 117 updated |

The same 210 characters generate an average 22.8 deactivation messages each in this window. This demonstrates repeated processing after refresh failure; it does not establish 4,787 unique token endpoint calls, their HTTP outcomes or why credentials failed. The 2,014 distinct failed-request characters versus 117 in character-refresh completion reinforces inconsistent eligibility across execution paths, not a measured healthy active population of 2,014.

**New P0 scope-classification requirement:** all five logged 401s concern the same character/corporation and explicitly identify a missing corporation-industry scope; each is accompanied by a token-refresh attempt. A refresh cannot add an ungranted scope. Check the required scope before dispatch/execution; classify an explicit insufficient-scope response separately from expired/invalid token responses, stop that dataset and request reauthorisation only when the user needs it. Do not deactivate unrelated datasets or keep refreshing the credential for this condition. Add a regression test proving an explicit missing-scope 401 does not trigger a refresh loop.

**New P1 manufacturing-write requirement:** deadlocks are now observed evidence, not only a hypothetical performance risk. The 11 error events are not necessarily 11 distinct failed jobs or deadlock transactions; nested/terminal exception logging can overlap. The logged exception events contain roughly 174,000–221,000 characters each, including bulk SQL values. This directly demonstrates oversized error logging and gives a plausible contributor to the large precompute exception storage identified in `failed_jobs`; separate payload and exception byte measurements are still required to attribute its 4,630.46 MiB.

Before raising precompute concurrency, enforce one writer per overlapping region/calculation slice, coalesce duplicate triggers from schedule/sweep/manual paths, sort upserts consistently by the actual unique key, and commit bounded chunks. Implement bounded retry with jitter for the entire failed transactional unit on error 1213; avoid replaying already completed generations or blindly retrying a permanently invalid operation. Capture a MySQL deadlock graph at recurrence to identify the actual competing writers and indexes; concurrent start messages alone do not prove which job pair deadlocked. Keep heavy precompute on the intended `updates` queue and verify leases, job timeout and `retry_after`. Add concurrent MySQL tests for duplicate triggers, deadlock recovery and deterministic writes.

Log bounded error metadata (SQLSTATE, driver code, region, generation, batch size and correlation ID) instead of full bulk statements/bindings; sanitise persisted failure exception content as well as application logs while preserving enough diagnostic context. Include a log-size assertion and independent failure-payload/exception retention in acceptance checks. These observations move write coordination and bounded diagnostics ahead of worker expansion in phase 1/3 delivery.

Immediate incident priorities:

1. Temporarily pause scheduled asset refresh dispatch and avoid manual forced refreshes while investigating. Already queued asset jobs must also be paused/deferred selectively; disabling the schedule alone will not stop them. Preserve the last complete data and display its age. This is an operational recommendation, not an action performed by this review.
2. Collect the logs before the first 420 (at least several preceding minutes), grouped by response status, endpoint and character. Identify repeated 401/403/404/5xx and transport failures, including missing scopes and structure lookups. Keep credentials out of logs. The 420 is a symptom of an exhausted budget, not proof that asset request volume itself caused the exhaustion.
3. Ship typed response/error classification, a shared 420 cooldown safeguard and deadline-based reset handling, then make asset jobs defer rather than publish failed/partial fetches. Stop all callers covered by the circuit, including public requests and direct asset-name requests. Investigate actual legacy error scope before narrowing the conservative safeguard.
4. Ship per-character token-refresh locking and separate permanent credential rejection from transient failure. Add safe SSO status/error-code telemetry; re-enable affected characters only after confirming their credentials are valid or completing reauthentication.
5. Resume a small asset-refresh cohort with staggered jobs and watch error budgets, status categories, source age and queue delay before expanding.

The full token limiter and durable snapshot work remain necessary. Do not increase workers as an incident response: that can accelerate failures while the current safeguards are incomplete.

## Scope and confidence

Reviewed the repository instructions, transport/authentication clients, character/corporation/industry/market/PI clients, asset enrichment and search, scheduled refresh commands and jobs, corporation project analytics, market sweep/aggregate publication, SQL migrations/models, Redis/Horizon/queue configuration, character removal and relevant test coverage.

Compared the code with CCP documentation and downloaded the public OpenAPI specification, including the project's `2025-12-16` compatibility date. Exact endpoint limits below were checked in that specification. A newer compatibility date should be adopted only after a schema diff and contract tests.

The code review was static. Production SQL summaries and log excerpts were subsequently supplied by the user and are incorporated as the measured pre-migration baseline. No direct production database access, query plans, runtime configuration or credentials were inspected. Tests and load tests were not run; no PHP executable was found on the command path. Repository instructions mention pre-existing test failures, but their current status was not independently verified. Population/storage/failure counts are supplied measurements; page counts, feature eligibility, latency and service targets below remain assumptions where explicitly labelled.

## 1. Current ESI requirements

ESI now uses token budgets by rate-limit group and identity. Authenticated identities combine application and character; unauthenticated identities depend on source IP. Responses cost 2 tokens for 2xx, 1 for 3xx, 5 for ordinary 4xx, and 0 for 5xx; 429 is excluded from the ordinary 4xx cost. Respect `Retry-After`, including 429s without bucket headers. Track the legacy error limit separately; it can produce 420s across routes. [CCP rate limiting](https://developers.eveonline.com/docs/services/esi/rate-limiting/).

Verified route groups at the application's compatibility date:

| Routes | Group | Tokens / window |
|---|---|---:|
| Character assets, asset names and locations | `char-asset` | 1,800 / 15 minutes |
| Corporation assets, names and locations | `corp-asset` | 1,800 / 15 minutes |
| Character blueprints, industry jobs, PI list/details | `char-industry` | 600 / 15 minutes |
| Corporation blueprints and industry jobs | `corp-industry` | 600 / 15 minutes |
| Character skills, skill queue and roles | `char-detail` | 600 / 15 minutes |
| Character location | `char-location` | 1,200 / 15 minutes |
| Corporation wallet journals | `corp-wallet` | 300 / 15 minutes |
| Corporation projects and contributors | `corp-project` | 600 / 15 minutes |
| Regional market orders | `market-order` | 12,000 / 15 minutes |

Source: [ESI OpenAPI for 2025-12-16](https://esi.evetech.net/meta/openapi.json?compatibility_date=2025-12-16). Refresh generated metadata as part of maintenance; do not treat this table as permanent configuration.

Send an application/version/contact User-Agent. Preserve cache validators and use `If-None-Match`. Validate consistency across paginated responses where `Last-Modified` is supplied. [CCP best practices](https://developers.eveonline.com/docs/services/esi/best-practices/).

Prefer `Cache-Control` for freshness. CCP's event-driven caching announcement specifically says `Expires` is no longer meaningful for converted skills/skillqueue routes. This describes server cache invalidation, not an application webhook subscription. [CCP caching update](https://developers.eveonline.com/blog/smarter-caching-when-events-drive-invalidation).

Pin one reviewed compatibility date for public and authenticated requests. An omitted date selects the oldest available behaviour; `/latest` does not replace explicit compatibility management. [ESI versioning](https://developers.eveonline.com/docs/services/esi/overview/).

## 2. Confirmed findings, in implementation order

### P0: Prevent incomplete data from becoming authoritative

**F1 — Shared pagination silently truncates data.** `app/Services/Esi/EsiHttpClient.php:296` stops on fewer than 1,000 rows, caps the scan at 20 pages and returns accumulated rows after a failed page. It discards `X-Pages` and other response metadata. Assets, blueprints and wallet journals use this helper. A small response page does not provide a universal completion rule, and a safety cap must not count as successful completion.

Change: preserve status/headers in a structured response; use `X-Pages` for offset endpoints and endpoint-specific cursor/from-ID handling elsewhere. Persist page progress; deduplicate by source record ID. If any required page fails, retain the previous complete snapshot. Any safety budget exhaustion must report incomplete and resume/retry rather than publish. Detect inconsistent page versions and restart/defer safely. See [X-Pages guidance](https://developers.eveonline.com/docs/services/esi/pagination/x-pages/).

**F2 — Other corporation datasets are also incomplete.** `Esi/IndustryClient.php:36` calls the corporation jobs endpoint once, although its specification includes `page` and `X-Pages`. `Esi/CorporationClient.php:315` fetches contributors once without `limit` or cursor: the checked specification defaults to 10. Its project-list loop also stops on a short page or 50 iterations and returns accumulated results after later failures.

Change: page corporation jobs; implement complete project/contributor scans and persist opaque cursors only after database commit. The project's initial `after=0` is explicitly supported by the checked route specification; keep it if using forward scans. A short cursor page alone must not end a scan. Store projects/contributors and update incrementally where supported, with periodic reconciliation. [Cursor pagination guidance](https://developers.eveonline.com/docs/services/esi/pagination/cursor-based/).

**F3 — Assets confuse empty, failed and partial results.** `CharacterClient.php:124` and `CorporationClient.php:87` cache any non-empty paginated result. `AssetService.php:180` combines owners and stores any non-empty aggregate forever. One owner's success can mask another owner's failure. Conversely, a successful empty inventory cannot replace an old non-empty aggregate (`AssetService.php:233`). A refresh timestamp currently reflects rebuilding, not necessarily the source data's freshness.

Change: distinguish `success`, `not_modified`, `deferred`, `forbidden`, `authentication_required`, `transient_failure` and `incomplete`. Valid empty inventories publish zero rows. Publish complete snapshots independently per owner, preserving failed owners' last complete data and freshness. Names/location enrichment may be pending without invalidating a complete core inventory.

### P0: Make throttling, credentials and failures recoverable

**F4 — Local rate limiting does not match ESI.** `EsiRateLimiter.php` assigns every group 300 requests/300 seconds; `EsiHttpClient.php:120` derives broad groups from URL prefixes. Character/corporation assets are merged, while blueprints, PI and industry are split despite sharing upstream groups. Market orders map to `markets`. No response token accounting or remote bucket cooldown is applied. Lock failure allows the request, and budget timestamp arrays live in evictable cache storage.

Change: generate method/route/group metadata from OpenAPI `x-rate-limit`; learn and reconcile `X-Ratelimit-*` headers; reserve tokens atomically across workers and account for actual response costs. Key by deployment/application, group and actual upstream identity. Put limiter state and cooldown deadlines on non-evicting Redis. On coordination failure, defer work. Implement group cooldown for 429 and a conservative shared circuit for 420; validate the legacy error identity scope against observed upstream behaviour rather than relying on the code's per-character assertion. Store absolute reset deadlines, not a cached duration that does not count down. A global throughput ceiling can supplement, but cannot replace, these buckets.

**F5 — Transport loses the information retries need.** `authenticatedRequest()` returns JSON or null, merging timeouts, 403s, 429s and server errors. Public requests have explicit connection/response timeouts; authenticated requests do not set equivalent explicit budgets. The central client lacks a descriptive User-Agent; public calls omit the compatibility date. Asset-name POSTs duplicate transport logic in two clients. `MarketClient.php:146` recognises local throttling but maps upstream 429/420 responses to generic failure.

Change: use one transport for public/authenticated GET/POST with configurable timeouts, identity headers, request IDs and typed outcomes. Refresh on 401 at most once; do not retry permanent validation/scope errors blindly. Release queue work until `Retry-After` plus jitter for throttles; use bounded exponential backoff for timeouts/5xx. Never sleep through a cooldown inside a worker. Preserve headers on 304 and error responses.

**F6 — An SSO outage can deactivate characters.** `EsiAuthClient.php:28` returns null for every unsuccessful refresh. `EsiHttpClient.php:244,272` then sets `is_esi_active=false`. No refresh lock prevents simultaneous jobs refreshing the same character or overwriting rotated credentials.

Change: acquire a per-character distributed refresh lock; reload the latest token and recheck expiry after acquiring it. Update rotated tokens atomically. Only confirmed permanent credential failures should require reauthentication; defer on SSO timeouts, throttling and server errors. Use a small expiry safety margin. Encrypt stored access/refresh tokens with a staged, backward-compatible migration; the model currently hides them from serialization but has no encrypted casts. Audit logs must exclude credentials. SSO credentials are sensitive and character/scopes specific. [CCP SSO](https://developers.eveonline.com/docs/services/sso/).

**F7 — Structure failures can be remembered as denial for 60 days.** `UniverseClient.php:254` records denial for any null result unless a preflight limit check predicted a skip. A network failure, upstream 429 or 5xx can therefore become a long denial. Persisted structure metadata is served without checking its age, and an outer structure-wide failure cache can suppress another character's attempt (`AssetService.php:1123`).

Change: record denial only for an explicit relevant 403, scoped to character and structure. Give network/throttle failures short retryable state. Add `last_verified_at` and expiry/revalidation to stored metadata; distinguish cached knowledge from evidence of current access. Bound credential fallbacks. Audit shared structure metadata against the intended privacy policy. Fix invalidation to use the actual `.char.{id}` keys; currently some callers clear/read an unsuffixed structure key.

### P1: Restructure scheduling and cache publication

**F8 — Refresh work is uneven and duplicated.** `RefreshCharacterData` performs a serial scan of every ESI-active character in the scheduler. It requests location/training and forces skill refresh; corporation/alliance metadata is invalidated repeatedly for characters in the same organisations. Affiliation is requested one character at a time rather than batched. `RefreshAssetCache` dispatches without staggering. `RefreshIndustryCache` loads all active users, including dormant accounts, rather than applying the seven-day login policy. Asset/industry services then load all linked characters without consistently filtering `is_esi_active`.

Change: a short dispatcher selects due character/dataset and corporation/dataset tasks. Use deterministic staggering, active-user/feature eligibility and bounded onboarding backfill. Batch affiliations within the spec's body limits; deduplicate organisation metadata. Apply scope checks before calls. Use separate freshness policies for assets, roles, industry, skills and location. Preserve the existing dashboard's useful 600-second dispatch spread.

**F9 — Jobs may report success after failure.** `RefreshAssetData` and `RefreshIndustryData` catch and log exceptions without rethrowing/releasing; declared retries do not address these swallowed failures. Scheduler overlap guards cover dispatch commands, not the lifetime of queued refreshes. Manual refreshes can enqueue duplicates and delete ESI caches while requests are still within their freshness window.

Change: make jobs idempotent and unique by owner/dataset; add runtime overlap locks and generation/fencing checks. Return a recorded outcome, release on deferral and throw retryable failures. Use retry deadlines that tolerate cooldowns without exhausting attempts through normal releases. The refresh button should coalesce a request for the earliest permissible revalidation and retain the old view. Audit every cache-clearing entry point.

**F10 — Eviction can turn a normal page request into a large ESI workload.** `AssetService.php:163` fetches inline on a cache miss, and search/grouping rebuilds arrays and trees in PHP. A forever cache entry can still be evicted. Duplicate character/corporation raw payloads plus enriched per-user payloads increase memory and serialization costs. Unlinking/changing the main character in `CharacterController` does not invalidate those asset aggregates; old rows can remain visible.

Change: durable SQL data supplies page requests; enqueue missing/stale refreshes and display last success, next attempt and pending/error state. Store small, versioned result caches. Authorise every read, including cache hits. Invalidate or version views on character unlink, main-character changes, corporation/role/scope changes and reauthentication. Decide whether unlink explicitly revokes the SSO grant; implement that policy consistently.

### P1: Correct SQL snapshot publication

**F11 — Market aggregates are not isolated by their published generation.** `MarketAggregateService.php:48` upserts live rows in chunks using unique `(region_id,type_id)`. `sellPrices():183` uses the published sweep ID in cache keys but queries SQL by region/type without `sweep_id`. During rebuilding, a cache miss can read new rows into an old sweep's key; a failed rebuild can leave partial replacement. Labels promise a complete snapshot that this schema cannot retain.

Change: store aggregates with unique `(region_id,sweep_id,type_id)` and a durable per-region active-sweep pointer. Build a new generation, validate, then atomically switch the pointer after completion. All cache-miss SQL must filter the chosen sweep. Retain the previous generation until old readers/cache keys expire. Handle the current `market_orders` table as a working ingestion table; any direct raw-order consumers can observe a running/failed sweep. Move those consumers to complete aggregates or stage full raw generations where they truly require a consistent order book.

Market hardening: persist page receipts unique `(sweep_id,page)` so retries cannot double-count; reject late writes from abandoned sweeps; lock a durable region row when starting/completing; recheck completeness under that lock. Validate changing `X-Pages`/`Last-Modified` before destructive reconciliation. Treat a disappearing page as a consistency event, not automatically proof a safe full inventory was collected. Existing complete-batch checks, chunked writes, ETags and deadlock retries are good starting points.

**F12 — Public contracts have a destructive refresh window.** `ImportPublicContracts.php:89` deletes both live tables before loading replacements. Readers see empty/partial data, and an import failure cannot preserve the old view.

Change: import into a new generation or staging tables, validate both files/counts/relationships, then publish atomically. Keep the prior generation for rollback. Coordinate SDE/Fuzzwork/ESI import writers too: their schedules and source ownership currently overlap.

## 3. Proposed SQL model

Use new dated migrations and chunked, restartable backfills. Avoid a giant transaction around HTTP requests or millions of row writes.

| Table | Purpose and proposed keys/indexes |
|---|---|
| `esi_sync_states` | One row per owner/dataset/request variant; unique `(owner_type,owner_id,dataset,variant_hash)`. Store active snapshot, last attempt/success, source timestamps, next due/retry time, cursor, failure class/count, auth version. Index `(status,next_due_at,id)` for due-work selection. |
| `esi_sync_runs` | Run ID, owner/dataset, status, expected/completed pages, counts, timings, credential ID and error summary. Unique page receipts in a child table `(run_id,page_key)`. Include lease/fencing generation. |
| `asset_snapshots` | Owner, generation, completion status, row count, source freshness, publication time. Never select an incomplete generation as current. |
| `asset_items` | Snapshot/owner, item/type IDs, quantity, raw location ID/type/flag, parent item ID, singleton state and optional name. Unique `(snapshot_id,item_id)`; indexes `(snapshot_id,type_id)` and `(snapshot_id,location_id)`; parent lookup can use location ID for `location_type=item`, or a dedicated `(snapshot_id,parent_item_id)`. Start with the minimum needed by measured queries. |
| `corporation_access_grants` | Character/corporation/dataset eligibility, scopes/roles checked time, credential/auth version, last success and failure. Unique `(corporation_id,character_id,dataset)`; select one eligible collector per dataset. |
| `esi_industry_jobs` | Owner/job identity, status/activity, installer, facility/product/blueprint and dates. Unique `(owner_type,owner_id,job_id)`; index owner/status/end date for current-job views. Preserve completed history according to retention policy. |
| `esi_blueprint_items` | Owner/item, type, location, quantity, ME/TE/runs and snapshot. Keep owned copies separate from SDE blueprint activity definitions. |
| `corporation_wallet_journal` | Unique `(corporation_id,division,journal_id)`; index `(corporation_id,division,date,journal_id)`. Upsert bounded history; respect source retention limits and pagination mode. |
| `corporation_projects`, `project_contributors` | Unique source project IDs and `(project_id,character_id)` contribution identity, plus corporation indexes, source update times and persisted listing cursors. Aggregate leaderboard reads in SQL. |
| `market_region_state` and versioned `market_aggregates` | Durable active sweep, with unique `(region_id,sweep_id,type_id)` in aggregates. Atomically switch the region pointer only after validated generation completion. |

Owner data and access grants are separate: sharing one corporation snapshot does not grant every linked user access. Recheck current application membership, scope and required role at read/dispatch time, with an explicit maximum age for verification. Purge or stop serving data when access is lost. Deduplication must not rotate credentials to circumvent rate limits.

Use 64-bit item/location/order/journal identifiers; preserve them safely across JSON/TypeScript boundaries. Preserve signed blueprint quantity/runs sentinel values. Add snapshot/auth versions to API responses and caches so obsolete results cannot overwrite newer state.

Existing SQL strengths and further improvements:

- Keep current pricing, market-history and character composite indexes; migrations already add `(user_id,is_esi_active)`, region/order-side/type indexes, region/update-time cleanup and manufacturing profit ordering. Check deployed migrations before adding equivalents.
- `AssetService::getMaterialsForUser()` still resolves `EveType::find()` per asset through `resolveGroupId()`, despite bulk price lookup. Bulk-load distinct type-to-group data. Bulk-load stations and memoize distinct resolved locations/structures per run; enrichment currently repeats those lookups per item. Guard nested-location traversal against cycles and excessive depth.
- Page asset queries and load container children on demand. Use scoped type/location totals for materials and stock calculations. An arbitrary `%term%` search will not be fixed by an ordinary B-tree; select prefix search or an appropriate full-text/search strategy after measuring requirements.
- Use exact decimals for wallet/contract monetary amounts; current market/contract schemas contain doubles. Validate required scale against upstream amounts before migration. Approximate derived analytics may remain floating point where acceptable.
- JSON in build plans/settings and SDE activity recipes is reasonable when read as a unit. Normalise fields only where filtering/joins become hot; owned asset inventories should be relational.
- Add a database constraint for at most one main character per user using a nullable generated column with a unique index, plus transactional switching. MySQL does not provide a general partial unique index.
- Set retention/pruning for old snapshots, sync/page receipts, market sweeps, Horizon failures and historical data. Assess redundant indexes with query plans; every extra index costs write IO. At 2,000 characters, asset/history row counts matter more than indexing a small character table.
- Run `EXPLAIN ANALYZE` on representative read queries in staging, inspect slow-query samples and DB lock/connection pressure. Verify import publication and decimal behaviour on MySQL 8, not solely SQLite.

## 4. Caching and job design

Use three distinct kinds of state:

1. **Durable SQL snapshots and sync state:** last complete core datasets and progress survive Redis loss.
2. **Non-evicting coordination Redis:** queues, job/refresh locks, token budgets and cooldown deadlines. Keep bounded state, persistence and capacity alerts.
3. **Evictable cache Redis:** HTTP representations and small derived views. A lost entry should cause a bounded SQL read and coalesced background refresh.

HTTP cache identity should include method, canonical path/query/body hash, tenant, language, reviewed compatibility date and appropriate subject/access version. Never use plaintext credentials as keys. Store body/validator/page count and freshness metadata together; a 304 is usable only if its representation remains available. Honour `Cache-Control`, `Date`/`Age`, validators and applicable directives, with safe fallback when headers are absent. Local manual refresh cannot bypass upstream freshness.

Return stale data during transient failure only while current authorisation permits it, showing source age and refresh status. Separate complete core asset data from optional enrichment state. Put a single-flight lock around cache misses, and use versioned publication so a slower old job cannot replace a newer result.

Refresh corporation datasets once per corporation/dataset, independently of user aggregates. Background collectors choose a valid credential; initial rollout can preserve the current main-character rule, then introduce explicitly selected corporation collectors. Avoid making every user's request try every character for inaccessible structures.

The default configuration places sessions on the evictable, nonpersistent cache Redis (`SESSION_CONNECTION=cache`). Separate sessions from bulk dataset cache, or reserve a non-evicting, persisted session store. Monitor queue Redis memory too: `noeviction` prevents eviction but cannot prevent write failures when capacity is exhausted.

Keep public market ingestion isolated from private owner sync and CPU-heavy precomputation. Within the current five-process ceiling, first test a private/background split and smaller jobs. If measurements justify a higher ceiling or separate host, update Horizon and `JobTimeoutTest` together. Maintain `request timeout < job timeout < retry_after`, including lock leases and termination grace; verify normal throttling releases do not become terminal failures.

## 5. Capacity model for 2,000+ characters

### Measured population and growth scenarios

Use 117 ESI-active characters / 26 eligible users as the active baseline, with 2,704 total linked characters as the existing registered population. The goal is reliable synchronisation for more than 2,000 eligible characters, not merely storing 2,000 character records.

| Scenario | Eligible characters | Multiple of 117 | User jobs at observed 4.50 active characters/user |
|---|---:|---:|---:|
| Observed active baseline | 117 | 1.00× | 26 |
| Primary scale target | 2,000 | 17.09× | About 445 |
| All currently linked characters eligible | 2,704 | 23.11× | About 601 if ownership matched the active cohort |
| Headroom test | 3,000 | 25.64× | About 667 |

User counts in the last column are conditional projections, not forecasts. The actual 2,704 linked characters are distributed across 1,225 users; use that measured ownership distribution when testing full reactivation, not the projected 601 users. Include both the observed 36-character account and a more concentrated synthetic account in fairness tests. The 34 represented corporations are not 34 authorised asset datasets, and corporation counts must not be scaled mechanically by 17.09×.

### Scheduling model informed by observed users

Applying the reviewed command selection rules to the supplied population gives the following dispatch model. These are derived scheduled-job counts, not measured job executions; pre-migration routing/schedules must be checked before treating them as observed traffic.

| Dispatcher | Selection / cadence in reviewed code | Derived jobs/hour |
|---|---|---:|
| Assets | 26 recent users with an active character, every 30 min | 52 |
| Dashboard | 59 recent enabled users, every 15 min | 236 |
| Industry | All 1,285 enabled users, every 30 min | 2,570 |
| Combined | Excludes market, imports and inline character refresh | 2,858 |

Filtering industry to the same 26 eligible users changes its dispatch count from 2,570 to 52/hour, a 98.0% reduction. Keeping dashboard selection unchanged gives 340 combined jobs/hour, 88.1% below the model above. Consider gating ESI-dependent dashboard work too while still serving local data to the other 33 recent users. This removes unnecessary dispatches; it does not demonstrate an equivalent reduction in HTTP calls because dormant jobs may already hit caches or skip work.

The character command's current scope is 117 ESI-active characters; a 30-minute cadence represents 234 character visits/hour, outside the queued-job table above. Add dataset eligibility, scope checks and a short due-work dispatcher rather than lengthening a serial scan.

The checkout permits up to two default workers, but production worker count is unmeasured. At two workers, 70% utilisation gives 5,040 usable worker-seconds/hour: the 2,858-job model permits only 1.76 seconds of mean service time per job before other work, versus 14.82 seconds for 340 jobs/hour. This is a capacity budget, not an observed execution time; the queues also face bursts, retries and CPU-heavy precompute in the supplied failure history. Fix dispatch/routing before increasing workers.

### HTTP workload: measured population, explicit unmeasured inputs

Model each dataset as `eligible owners × mean requests per refresh / refresh interval`. Do not count every character as PI-enabled or every represented corporation as authorised. Use this sensitivity table until scope/page measurements arrive:

| Dataset | Unknowns / illustrative assumption | At 117 active characters | At 2,000 active characters |
|---|---|---:|---:|
| Personal assets | All eligible; 3 pages hourly | 351 requests/hour | 6,000 requests/hour |
| Asset names | All eligible; 1 POST hourly | 117/hour | 2,000/hour |
| Skills, queue, roles, location | All four scoped; every 30 min | 936/hour | 16,000/hour |
| PI list + colony details | All PI-enabled; 6 colonies; every 15 min | 3,276/hour | 56,000/hour |
| Personal subtotal under those assumptions | Excludes corp, market, auth, jobs, blueprints, metadata, retries | 4,680/hour = 1.30/s | 80,000/hour = 22.22/s |

Only the population columns are measured inputs. All eligibility/page/cadence assumptions are hypothetical and subordinate to upstream freshness. For PI, multiply the table estimate by the actual PI-enabled fraction and adjust colonies per owner. Corporation assets add `authorised corporations × pages / refresh interval`; the only observed corporation figure is 34 represented, which is insufficient to calculate this term. Market ingestion is shared overhead and must be measured separately.

Worker sensitivity: `workers >= HTTP requests/second × mean external latency / utilisation`, plus CPU/SQL work. At 70% utilisation and hypothetical 0.5-second latency, the personal subtotal implies about 1 worker at 117 and 16 workers at 2,000; at 0.1 seconds, about 1 and 4 respectively. These are rounded-up request-service equivalents, not a host recommendation, and omit important datasets. Bounded concurrency, cache freshness, active-feature filtering and corporation deduplication can materially change the result. No worker count is justified yet by the supplied measurements.

### Asset storage and Redis baseline

Asset row counts remain unmeasured. A partial Redis scan supplied by the user returned the following four aggregate cache keys. The scan's next cursor is `64072`, so this is not a complete inventory or a random representative sample. The SQL baseline has no durable inventory table and cannot tell us personal/corporation asset volume. Do not extrapolate the 12.56 MiB character table into asset storage.

| Aggregate cache | Redis memory bytes | Payload bytes | TTL seconds |
|---|---:|---:|---:|
| `assets.user.953` | 3,670,072 | 3,428,108 | -1 |
| `assets.user.192` | 524,344 | 463,616 | -1 |
| `assets.user.995` | 2,097,208 | 2,001,925 | -1 |
| `assets.user.1191` | 524,344 | 504,254 | -1 |
| Partial-sample total | 6,815,968 (6.50 MiB) | 6,397,903 (6.10 MiB) | — |

The actual keys include prefix `eve_auth_cache_`. All four are persistent keys (`TTL=-1`); that does not establish their data age or protect them from memory eviction. The largest uses 3.50 MiB and the smallest about 0.50 MiB, already demonstrating meaningful variation. Sample mean memory is 1.625 MiB/key, but must not be extrapolated to all users or characters before scan completion and cohort/freshness checks. Redis memory excludes the potentially much larger decoded PHP arrays, tree building and response serialization. This sample reinforces bounded reads, durable inventory storage and avoiding full-payload copies per request; it does not establish host RAM requirements.

A subsequent Redis Insight list adds 11 distinct per-user keys: user 1364 (1 MB); 523, 1356 and 1215 (896 KB each); 162 (768 KB); 1255 and 826 (640 KB each); 1379 and 1336 (512 KB each); 908 (448 KB); and 1327 (192 KB). It also repeats users 192 and 1191 (512 KB each), which must not be counted twice. Together the supplied samples cover 15 distinct aggregate user keys. These UI-rounded sizes are not interchangeable with the prior exact payload/memory-byte columns, and this listing is not evidence that the full scan has completed.

The same list includes raw character key `eve_auth_cache_esi.assets.2116098904`, shown as 768 KB with 41 minutes remaining, while every listed aggregate user key has no expiry. This directly confirms coexistence of raw character inventories and persistent enriched per-user views; it does not quantify duplication without ownership/row mapping. Unknowns remain total key counts, complete bytes by layer, corporation caches, cache ages and eligible-cohort coverage. Test the migration against both source-expiry and stale-aggregate behaviour. Do not equate the 15 observed user keys with the measured 26 eligible users: the caches can include dormant users and the samples may have different capture times.

#### Larger-cache evidence: Redis Insight top 15

A later top-15-by-memory listing shows a materially larger upper end than the initial sample. All 15 listed keys are `assets.user.*` strings without expiry. The largest, user 440, is displayed as 12 MB and has an exact string length of 10,589,287 bytes (10.10 MiB). Users 1130, 1339 and 1362 are each displayed as 6 MB; users 1244 and 1281 as 5 MB; user 892 as 4 MB; users 953, 292, 1241, 1300, 1272 and 1233 as 3.5 MB; users 1053 and 1380 as 3 MB. These rounded memory labels total 71 displayed MB; do not treat that as exact bytes or the database total. The exact payload lengths supplied for those 15 sum to 68,270,661 bytes (65.11 MiB).

Use the 10,589,287-byte serialized payload as an observed large-cache test fixture size. Measure decoded PHP memory, tree/filter/sort allocations, response serialization, latency and concurrent-request peak memory against the configured 128 MiB default-worker budget and actual web-worker limits. No fixed serialized-to-decoded multiplier or guaranteed out-of-memory failure can be inferred. This evidence strengthens the priority for SQL pagination, small summaries, loading container children on demand and removing full-inventory work from request paths. A top-15 selection is intentionally biased toward large keys; do not extrapolate its mean to all users or infer item counts from serialized bytes. Continue to collect full per-layer totals separately, deduplicating repeated samples such as user 953.

For sensitivity only, 10,000 items per eligible character would imply 1.17 million personal items at 117, 20 million at 2,000, and 27.04 million for all 2,704 linked characters. Size SQL from measured rows, row/index bytes and retained/staging generations. Add corporation assets once per authorised corporation, not once per user.

Collect Redis memory and payload bytes separately for `assets.user.*`, `esi.assets.*` and `esi.corp_assets.*`, excluding `.cached_at` metadata from inventory-key counts. Record sample time and full scan completion. A missing key does not establish empty inventory; stale keys for dormant users can exist. Aggregate user payloads mix personal/corporation/enrichment data, so neither all-user bytes nor personal bytes should be multiplied blindly by 17.09. Report cohort-specific totals, p50/p95/max payloads, freshness and duplicate corp coverage. Redis Insight Workbench can use a bounded SCAN-based script returning only key metadata; do not decode Laravel serialized payloads inside Redis scripts.

## 6. Delivery plan and acceptance gates

| Phase | Changes | Completion criteria |
|---|---|---|
| 0 — Complete the measured baseline | Retain the supplied 117-active/2,704-linked population, storage and failed-job counts; add request/status/group metrics, scope/page/Redis sizes, queue timings, worker configuration and sampled SQL plans. | Population/storage/failure baseline in this document is preserved; remaining runtime gaps are measured without requiring absent pre-migration sweep tables. |
| 1 — Correctness and recovery | F1–F7: structured transport, all pagination paths, empty/failure semantics, correct limits/cooldowns, refresh lock, structure failure classification. Minimal safe per-owner publication while SQL migration is prepared. | More than 20 asset pages collected; failures never publish partial lists; complete empty results clear data; multi-page corp jobs and >10 contributors verified; transient SSO errors never deactivate characters. |
| 2 — Durable sync and serving | New sync/snapshot tables; owner jobs; due-work dispatch; corporation collectors; SQL-backed asset reads; cache versions and authorisation invalidation. | Warm pages perform no ESI calls; cold pages enqueue bounded work and return a documented pending state; Redis flush preserves inventory; unlink/corporation/role changes remove access and obsolete rows promptly. |
| 3 — SQL publication and efficiency | Versioned market aggregates and contract imports; page receipts/fencing; batch asset enrichment; measured indexes; paginated UI; retention. | Readers see one complete generation during ingestion and crashes; duplicates/late jobs cannot change completion counts or publish; query counts grow with distinct batches, not asset rows. |
| 4 — Scale validation and rollout | Replay the 117-character/26-user baseline, then test 2,000 eligible, 2,704 linked with measured ownership, and 3,000 eligible characters; tune queues/concurrency and reactivate progressively. | Targets below pass with observed 36-character-account cases, large corporations, outages and recovery; fresh terminal failure rates and queue lag improve without hiding work through deactivation. |

Split phase 1 into reviewable changes: (a) response/pagination completeness, (b) limiter/cooldown state, (c) token refresh and error classification. Avoid coupling all changes into one migration or deployment.

Add two early, independently reviewable tasks informed by the failure baseline: constrain industry dispatch to eligible owners, and isolate/reduce manufacturing-precompute failure payloads on the intended queue. Preserve diagnostic samples, then implement SQL failure retention with separate Horizon retention. Measure terminal failures per executed job and per dataset, not only the absolute count; the current 506/week is a baseline count without an execution denominator. Confirm improvement with a matched eligible cohort and workload mix.

Proposed service targets, to confirm against product freshness needs:

- Warm asset/search API p95 under 500 ms for bounded pages of at most 100 rows, measured end-to-end at a stated concurrent-user load; suggested initial test: 100 concurrent sessions.
- UI first response under two seconds on cold data, showing pending state rather than waiting for ESI.
- 99% of eligible refreshes start within five minutes of `next_due_at` during normal upstream availability; last-success age measured per dataset. Exclude intentional upstream cooldowns from queue-delay alarms while still reporting data staleness.
- Zero publication of incomplete snapshots; valid empty snapshots supported; no automatic deactivation due to transient SSO failure.
- No steady-state throttling caused by local accounting errors; avoid operating close to token exhaustion. Alerts distinguish upstream outage, credential failure, local queue lag and SQL contention.
- Bounded worker memory under configured limits, stable Redis memory after a full retention cycle, and documented SQL storage growth.

## 7. Required validation

Add targeted tests for: short intermediate pages, 21+ pages, changed page counts/version headers, duplicate records, failed final page, 304 without stored body, legitimate empty inventory, owner-level partial failure and out-of-order completion. Cover all paginated corporation datasets, including cursor loops and more than 100 contributors.

Use shared Redis integration tests for concurrent bucket reservations, header reconciliation, lock failure, 429 without group headers, 420 cooldown, absolute reset deadlines and a single concurrent token refresh. Array-cache tests cannot establish distributed correctness. Update existing limiter tests that encode the old group/count assumptions.

Use MySQL integration tests for snapshot publication rollback/crashes, late and duplicate page jobs, unique constraints, decimal precision and read authorisation after unlink/role/corporation changes. Test Redis eviction/restart, SSO outage and expired credentials. Do not run a synthetic load test against live ESI; use recorded/synthetic fixtures and controlled failure/latency injection, followed by a small real-traffic canary.

Staging profiles: the observed 117 active characters across 26 users, with 133 linked characters including 16 inactive; 59 recent users including 33 without an active character; 34 represented corporations with explicit authorised subsets; a 36-linked-character account. Then test 2,000/3,000 eligible characters and the full 2,704 linked across 1,225 users with separate eligibility rules. Add small/large/empty inventories, a corporation beyond the old page cap, shared corporations, zero-to-six PI colonies and more than 100 project contributors. Include onboarding bursts, steady state, SSO failure and recovery. The 115 expired access tokens motivate a simultaneous-expiry stress case; do not infer they all expired together historically.

Roll out by dataset/cohort: canary roughly 25 of the current 117 active characters, then the full existing active cohort; validate returning users/credentials before expanding through 250, 1,000, 2,000 and the remaining eligible population. Do not mass-enable the 2,587 inactive characters. Shadow-compare counts/totals, then switch readers behind a feature flag. Stop duplicate old collectors. Retain previous generations for rollback without copying obsolete authorisation. Change schemas through new migrations; regenerate the checked-in API specification/frontend types when API responses change.

## 8. Measurements needed before final infrastructure sizing

Already supplied: total/recent/enabled users, linked/active/eligible characters, eligible-account mean/max size, represented corporations, token presence/expiry, approximate SQL allocations and grouped failed-job counts/bytes. Use these as the pre-migration baseline; do not request them again as prerequisites.

Still unmeasured: distinct authorised corporations by dataset; scope coverage; personal/corporation asset pages/items/singletons; PI eligibility/colonies; projects/contributors; desired freshness; ESI latency/status/group rates and cache hits/304s; queue arrivals, waits, successful executions and run times; SQL query plans/index deployment; PHP peak memory; Redis inventory sizes/evictions; SSO failure categories and inactive reasons; deployed worker counts and host resources. The plan can proceed on correctness, scheduling, queue isolation and retention while these measurements are added.

With that evidence, tune freshness and worker concurrency rather than setting a universal requests-per-second limit. The first deployment priority is complete and recoverable data collection, followed by durable owner snapshots and background-only refreshes.
