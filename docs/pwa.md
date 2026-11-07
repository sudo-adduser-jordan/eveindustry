# PWA

The React app at `/app` is installable and keeps working as a shell when offline. The Blade pages (`/login`, `/admin`, SSO) are not part of it.

## Scope and caching

- The service worker is `public/app/sw.js` (source `frontend/src/sw.ts`, built by `vite-plugin-pwa` with `injectManifest`). Its scope is `/app/`, so it never sees `/api`, `/sanctum`, `/login` or `/auth`. Keep it that way: those paths carry the session cookie, CSRF token and the 401 → `/login` redirect.
- Precached: `index.html` and the hashed files in `static/`. Nothing else is cached by the worker, and no API response is ever stored by it.
- Navigations always go to the network first, so Laravel's auth redirect applies. Only when the network is unreachable does the worker serve the precached shell.
- Installed manifest: `manifest.webmanifest` (`id`/`scope` `/app/`, `start_url` `/app/dashboard`). Icons live in `frontend/public/` and are copied to `public/app/`.

## Updates

`registerType: 'prompt'`: a new build installs in the background and the shell shows "A new version is available" (`PwaStatus`). Accepting sends `SKIP_WAITING` and reloads. Installed apps poll for a new worker hourly.

## Server requirements

`sw.js` and `manifest.webmanifest` must be served with `Cache-Control: no-cache`, and `/app/static/` may be `immutable`. Configure the same three `location` blocks in nginx; without the `no-cache` header on `sw.js`, clients can be stuck on an old worker.

## Development

The worker is only registered in production builds (`import.meta.env.PROD`). To test it, run `npm run build` in `frontend/` and open the app through Laravel (not `vite dev`), then use DevTools → Application. To reset, unregister the worker and clear site data.

## Persisted query cache

`frontend/src/api/persistence.ts` saves part of the TanStack Query cache to IndexedDB (`PersistQueryClientProvider` in `main.tsx`), so the app opens with the last data and then revalidates in the background.

- **What is saved**: an allowlist in `shouldPersist` (`me` with characters, `reference`, `plans`, `industry`, the asset materials/ships/abyssal views, reaction skills, moon-scan systems, wormhole maps). Searches, live market data, paginated assets and location feeds are not. Add a key there deliberately; never persist something another user must not see.
- **Freshness**: data older than 30 s revalidates on open, window focus and reconnect (`staleTime` in `main.tsx`); `['me']` is always refetched on reconnect. Cached data shows while offline (`networkMode: 'offlineFirst'`).
- **Safety**: stored data expires after 24 h and on every new build (`buster`). It is wiped on logout, account deletion and any 401 (`leaveSession`), and if `/me` resolves to a different user every other query is reset (`watchSignedInUser`). If IndexedDB is unavailable the app runs without persistence.

## Push notifications

Web push for the installed or production app, built on `minishlink/web-push` (no Laravel channel package).

**Setup.** Generate a key pair once with `php artisan notifications:vapid-keys` and set `WEBPUSH_VAPID_PUBLIC_KEY`, `WEBPUSH_VAPID_PRIVATE_KEY` (and optionally `WEBPUSH_VAPID_SUBJECT`, a `mailto:` or site URL). Until both keys exist the scheduler sends nothing and Notifications settings says push is not configured. Rotating the keys invalidates every subscribed device, so treat the private key like any other secret.

**Flow.** The settings page (`/app/notifications`) subscribes the browser (button-triggered permission prompt) and registers it with `PUT /api/v1/notifications/subscription`. `notifications:dispatch` runs every 5 minutes and queues `DispatchUserNotifications` (queue `default`) only for active users with at least one device. `NotificationDispatcher` asks each enabled type's detector (`app/Services/Notifications/Detectors`) for due events, claims each in `notification_log` (unique per user and `dedupe_key`, kept 14 days) and sends to every device. Subscriptions the push service reports gone (404/410) are deleted; an event no device accepted is retried on the next run.

**Rules for detectors.** They read the database and `EsiService`-cached data only (never call ESI directly), return one `PushMessage` per real-world event with a stable `dedupeKey`, and ignore anything before `$since` (the earliest device subscription, at most 6 hours back), so enabling push never announces old events.

**Types** live in `NotificationType` (the single source for the dispatcher, validation and the settings page): `industry.job_completed` (on by default), `pi.extractor_expiring`, `structure.window_opening`, `structure.timer` (all off by default; corporation types need a character in a corporation). To add one: add a case with its metadata, write a detector, register it in `AppServiceProvider`. The settings page needs no change. Users can switch types on or off, pick a lead time, limit industry and planetary types to some characters, and set quiet hours; events due during quiet hours are sent once they end (within the lookback).

**Not built yet.** `industry.slots_idle`, `pi.colony_idle`, ESS payouts, price alerts and build-program shortages.

**Service worker.** `sw.ts` shows the notification and, on click, focuses an open `/app/` window or opens one. It only navigates to `/app/…` paths whatever the payload says. Logout removes this browser's subscription first, so the next person on the device is not sent the previous user's notifications.

**iOS** receives web push only from the app added to the Home Screen (iOS 16.4+).

## Install prompt

`frontend/src/pwa/install.ts` captures the browser's `beforeinstallprompt` at startup (`captureInstallPrompt` in `main.tsx`). `InstallPrompt` (in `Shell`) opens a dismissible popup when the app can be installed. It stays up, across reloads, until the user clicks "Not now" or the close button; that time is stored in `localStorage` (`pwa-install-prompt-dismissed-at`) and the popup then stays away for 24 hours. The Notifications page has its own "Install app to enable notifications" button that is always available while the app can be installed and this device is not subscribed. iOS has no install event, so both places show an "Add to Home Screen" hint instead. Nothing is shown inside an installed app.
