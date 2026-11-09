# Demo mode

A shared demo account lets visitors try the site without EVE SSO. It is off unless `DEMO_ENABLED=true`.

## How it works

- **Entry:** "Try the demo" on the in-app login page (`/app/login`) posts to `POST /demo/login`, which signs in the single user with `is_demo = true` (email `demo@eve-industrialist.invalid`). The route returns 404 when demo mode is off. Legacy `/login` and `/welcome` URLs redirect to `/app/login`.
- **Characters:** `DemoDataService::reset()` deletes the demo user's characters and content and generates three new characters with made-up names (`DemoNames`) and ids in a reserved range (`DemoIds`: characters from 99,000,000,000, corporations from 99,100,000,000, alliances from 99,200,000,000). Real ids never fall in that range. The first character is the main and a corporation director.
- **Images:** portraits and logos for demo ids are generated SVGs from `DemoImageController` (`/demo/portrait/{id}.svg`, `/demo/logo/{id}.svg`). `Character` accessors, `CorporateDonationService` and the frontend `portraitUrl()` switch on the id range.
- **Data:** `EsiHttpClient` hands any request for a demo character, corporation, alliance or planet to `DemoEsiResponder`, which returns ESI-shaped skills, blueprints, assets, industry jobs and planets derived from the character id. No HTTP request is made, no token is refreshed. Blueprints and products come from the real SDE tables, so the dev database needs `sde:import` data.
- **Reset:** `demo:reset` runs daily at `DEMO_RESET_TIME` (default 03:30 UTC) and warms the asset, industry and dashboard caches. Run it by hand after enabling the flag.
- **Restrictions:** the `demo-guard` middleware (`BlockDemoUser`) rejects every write by the demo user that is not on its allow-list (calculators, appraisals, personal build programs, stock items, structures, station configs, refresh buttons). Blocked writes answer 403 with `code: demo_restricted`. Starting an EVE login from a demo session signs it out first, so a real character can never be linked to the shared account.
- **Background work:** `notifications:dispatch` and `esi:refresh-tokens` skip demo users and characters. Characters are `is_esi_active`, so the refresh commands may run for them, and they are answered locally.

## Frontend

`/me` returns `demo: true`. The shell shows a banner, hides "Add character", and the Characters and Notifications pages hide their account and push controls.
