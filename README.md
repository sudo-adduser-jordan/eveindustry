# Eve Industrialist — Go API

Go (Echo + sqlx) port of the EVE Online industry tracker. Serves the JSON
API at `/api/v1` consumed by `frontend/` (React, Vite, TypeScript).
The original Laravel application is preserved untouched under `example/`
for reference while the port completes; it is deleted at cutover.

## Layout

| Path | Contents |
|------|----------|
| `cmd/api` | API server (`GET /up`, `/api/v1/*`) |
| `cmd/worker` | Background job runner (skeleton: Phase jobs) |
| `cmd/scheduler` | Cron tick table (skeleton: Phase jobs) |
| `cmd/migrate` | DB migrations runner (placeholder: Phase migrations) |
| `internal/http` | Echo router, handlers, `{data, meta}` envelope |
| `internal/config` | Env-based config |
| `internal/store` | sqlx data layer (tables mirror `example/database/migrations`) |
| `internal/auth` | Session cookies, `/me` shaping, demo-guard |
| `internal/market` | Pricing maths, freshness, trading scanners |
| `internal/calc` | Calculator maths (manufacturing, reactions, invention, planners, browsers) |
| `internal/industry` | Blueprints, invention chains, jobs overview, production |
| `internal/pi` | Factory-planet planner |
| `internal/esi` | ESI transport (rate limiter, circuit, token refresh, clients) |
| `frontend/` | React SPA (typed from `docs/openapi.json`) |
| `example/` | Original Laravel app (reference only — do not extend) |
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
