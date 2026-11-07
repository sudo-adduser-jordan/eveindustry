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
> its logos are trademarks of CCP hf., which has not endorsed this project.

## Customise

* Colours, fonts and layout tokens: switch looks with `APP_THEME` and write your own theme without
  rebuilding anything. See the [styling guide](docs/styling.md).
* Community link and source link: `DISCORD_INVITE_URL`, `APP_SOURCE_URL`.

## Develop

Requirements: PHP 8.2+, Composer, Node 22.

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
```

Tests: `php artisan test` (SQLite in memory, no MySQL or Redis needed) and `npm test` in
[`frontend/`](frontend/). Start with [AGENTS.md](AGENTS.md)
(architecture, services, scheduled tasks, queues).

Stack: Laravel 12, PHP 8.5, MySQL 8, Redis, Horizon; React 19 + TypeScript (Vite) served at `/app`.
