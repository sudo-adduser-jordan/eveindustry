# Eve Industrialist

Hosted Version: [EVE Industry Hosted](https://eveindustry.app)

Self-hostable planning and tracking tools for [EVE Online](https://www.eveonline.com) industrialists.
Sign in with EVE SSO, link your characters, and use calculators and trackers built on your ESI
data plus public market data:

* manufacturing, reaction and invention calculators, build programs
* assets, blueprints, industry jobs, stock
* planetary industry, market trading, contract appraisal
* wormhole mapper, moon scans
* corporation tools: donations, projects, ESS tracker, structure timers
* installable web app (PWA) with optional push notifications

> **Unofficial fan project, provided as is.** No warranty, no support obligation. EVE Online and
> its logos are trademarks of CCP hf., which has not endorsed this project. See [NOTICE](NOTICE).

## Install with Docker (about 5 minutes)

You need a machine with [Docker](https://docs.docker.com/engine/install/) (Compose v2), ports 80 and
443 open, and (for HTTPS) a domain name pointing at it. 2 CPU cores and 4 GB RAM are a sensible minimum.

Linux, macOS or WSL:

```bash
curl -fsSL https://raw.githubusercontent.com/Eve-Industry/eve-industry/main/deploy/install.sh | bash
```

Windows (Docker Desktop), in PowerShell:

```powershell
irm https://raw.githubusercontent.com/Eve-Industry/eve-industry/main/deploy/install.ps1 | iex
```

The installer asks for your domain and the credentials of an EVE application
([how to create one](docs/self-hosting/eve-sso.md)), generates all secrets, starts the stack
(with automatic HTTPS through Caddy) and prints the admin login. Item and price data then import in
the background; `GET /api/v1/health` shows progress under `setup`.

Prefer to read what you run? Download the files from [`deploy/`](deploy/), copy `.env.example` to
`.env`, fill it in and run `docker compose up -d`. Everything is explained in
[docs/self-hosting](docs/self-hosting/README.md): HTTPS and proxies, updates, backups, push
notifications, troubleshooting.

Try it locally first with `DOMAIN=localhost` (no HTTPS).

Running Kubernetes? There is a Helm chart: [docs/self-hosting/kubernetes.md](docs/self-hosting/kubernetes.md).

## Customise

* Colours, fonts and layout tokens: switch looks with `APP_THEME` and write your own theme without
  rebuilding anything. See the [styling guide](docs/styling.md).
* Community link and source link: `DISCORD_INVITE_URL`, `APP_SOURCE_URL`.

## Develop

Requirements: PHP 8.2+, Composer, Node 22. Or use Docker only:

```bash
cp .env.example .env
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec app bash -c "composer install && php artisan key:generate && php artisan migrate && php artisan db:seed"
```

Tests: `php artisan test` (SQLite in memory, no MySQL or Redis needed) and `npm test` in
[`frontend/`](frontend/). Start with [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md)
(architecture, services, scheduled tasks, queues).

Stack: Laravel 12, PHP 8.5, MySQL 8, Redis, Horizon; React 19 + TypeScript (Vite) served at `/app`.

## Security

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

## Licence

[GNU Affero General Public License v3.0 or later](LICENSE). In short: you may use, modify and host
it, but if you run a modified version for others over a network you must offer them your source.
The software is provided without any warranty and the authors accept no responsibility for its use.
