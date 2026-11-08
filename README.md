# Eve Industrialist — Go API

Go (Echo + sqlx) API for the EVE Online industry tracker. Serves the JSON
API at `/api/v1` consumed by `frontend/` (React, Vite, TypeScript).

## Layout

| Path | Contents |
|------|----------|
| `cmd/api` | API server (`GET /up`, `/api/v1/*`) |
| `cmd/worker` | Background job runner (Redis queues) |
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

## Run

```sh
go build ./...
go test ./...        # or: go test -count=1 ./...
gofmt -l .           # must print nothing
go vet ./...
```

Config is all environment (`PORT`, `DB_HOST`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `EVE_CLIENT_ID`,
`EVE_CLIENT_SECRET`, `SESSION_KEY` or `APP_KEY`). Missing DB/Redis does not
crash the server: `/api/v1/health` reports `failed` and data routes 503.

## Conventions

- Handlers stay thin; logic lives in `internal/<domain>` with unit tests.
- New endpoints need a handler test with stubbed stores (`internal/http`).
- Error envelope `{message, code, errors?}` matches the PHP
  `ApiResponse::codeForStatus` vocabulary.
- Validation 422s mirror the PHP FormRequest rules field for field.
