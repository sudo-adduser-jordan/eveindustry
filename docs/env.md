# Environment

Copy `.env.example` to `.env` for local development. Every variable is optional. See `internal/config` for loading.

Server:

- `PORT=8080`
- `APP_ENV=local`
- `APP_URL=http://localhost:8080`
- `APP_SOURCE_URL=https://github.com/sudo-adduser-jordan/eveindustry`

Database:

- `DB_PATH=storage/eveindustry.sqlite`, embedded SQLite file created on open. Run `task migrate -- up` to make the schema.

Sessions:

- `SESSION_KEY`, `APP_KEY`, stateless HMAC cookies. Empty means ephemeral key for local dev, logins die on restart. `APP_KEY` also decrypts ESI tokens stored encrypted at rest.
- `SESSION_SECURE_COOKIE=false`
- `SESSION_DOMAIN=`

EVE SSO:

- `EVE_CLIENT_ID`, `EVE_CLIENT_SECRET`, empty disables SSO, demo still works.
- `ESI_USER_AGENT=EveIndustrialist/1.0`
- `ESI_COMPATIBILITY_DATE=`

Demo:

- `DEMO_ENABLED=true`
- `DEMO_RESET_TIME=03:30`, account named Try the demo, data resets daily 03:30 UTC.

Diagnostics:

- `ESI_DIAGNOSTICS_ENABLED=false`, Admin ESI Diagnostics off unless enabled.

Push:

- `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, empty keeps push off.

Uploads:

- `SPONSOR_DIR=storage/sponsors`

Run lanes:

- `APP_ROLE=worker-all`, one of api, worker, worker-market, worker-updates, worker-all.
- `UI_PORT=5173`, Vite dev server proxying `/api` to the Go api.
- `LARAVEL_URL=http://localhost:8080`
