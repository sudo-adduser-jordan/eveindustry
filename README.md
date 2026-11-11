# Eve Industrialist — Go API

Go (Echo + sqlx) API for the EVE Online industry tracker. Serves the JSON
API at `/api/v1` consumed by `frontend/` (React, Vite, TypeScript).

## Layout

| Path | Contents |
|------|----------|
| `cmd/api` | API server (`GET /up`, `/api/v1/*`) |
| `cmd/worker` | Background job runner (SQLite queues) |
| `cmd/scheduler` | Cron tick table (scheduled dispatch) |
| `cmd/migrate` | DB migrations runner (`migrations/*.up/down.sql`) |
| `internal/http` | Echo router, handlers, `{data, meta}` envelope |
| `internal/config` | Env-based config |
| `internal/store` | sqlx data layer (`migrations/*.sql`) |
| `internal/auth` | Session cookies, `/me` shaping, demo-guard |
| `internal/market` | Pricing maths, freshness, trading scanners |
| `internal/calc` | Calculator maths (manufacturing, reactions, invention, planners, browsers) |
| `internal/industry` | Blueprints, invention chains, jobs overview, production |
| `internal/pi` | Factory-planet planner |
| `internal/esi` | ESI transport (rate limiter, circuit, token refresh, clients) |
| `frontend/` | React SPA (typed from `docs/openapi.json`) |
| `migrations/` | SQL schema (one `.up.sql`/`.down.sql` pair per version) |
| `plan.md` | Port phases, conventions, remaining work |

## Setup (fresh checkout)

Prerequisites: Go 1.24+, Node 20+, and the [go-task](https://taskfile.dev/) runner.

```sh
task setup
```

This creates `.env` from `.env.example` (first run only), installs Go and
frontend dependencies, applies migrations, fetches the SDE reference data
(the long pole; skipped on reruns when the checksum is unchanged) and resets
the demo account. Afterwards:

```sh
task start   # api + worker + scheduler + web UI (demo mode on by default)
```

Useful slices of the same flow:

```sh
task deps            # install deps only (no network-heavy imports)
task deps:update     # update Go + frontend deps to latest allowed versions
task migrate -- up  # apply pending SQL migrations
task setup:sde       # fetch SDE reference data now (scheduler runs it daily)
task setup:demo      # reset demo data now (scheduler runs it daily)
task job -- <name> [json-payload]  # run any background job once and exit
```

Config is all environment; see `.env.example` (`PORT`, `DB_PATH`,
`EVE_CLIENT_ID`, `EVE_CLIENT_SECRET`, `SESSION_KEY` or `APP_KEY`,
`DEMO_ENABLED`, ...). The database is an embedded SQLite file (`DB_PATH`,
default `storage/eveindustry.sqlite`); run `go run ./cmd/migrate up` once to
create the schema.

## Run

```sh
go build ./...
go test ./...        # or: go test -count=1 ./...
gofmt -l .           # must print nothing
go vet ./...
```

Config is all environment (`PORT`, `DB_PATH`, `EVE_CLIENT_ID`,
`EVE_CLIENT_SECRET`, `SESSION_KEY` or `APP_KEY`, `DEMO_ENABLED`). The database
is an embedded SQLite file (`DB_PATH`, default `storage/eveindustry.sqlite`);
run `go run ./cmd/migrate up` once to create the schema.

## Conventions

- Handlers stay thin; logic lives in `internal/<domain>` with unit tests.
- New endpoints need a handler test with stubbed stores (`internal/http`).
- Error envelope `{message, code, errors?}` matches the PHP
  `ApiResponse::codeForStatus` vocabulary.
- Validation 422s mirror the PHP FormRequest rules field for field.
