<?php

use App\Services\MarketOrderSweepService;
use App\Services\MarketService;
use Illuminate\Support\Facades\Schedule;

// ──────────────────────────────────────────────────────────────────────
// Every scheduled task. Times are UTC. A single scheduler process
// (cron running schedule:run, or `php artisan schedule:work`) must run
// in each environment.
//
// Two tasks are switched off below: esi:refresh-tokens (tokens are
// refreshed on demand in EsiService) and market:fetch-region-history
// (replaced by the everef history import).
// ──────────────────────────────────────────────────────────────────────

Schedule::command('sde:import')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('market:update-prices')->daily()->at('04:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('esi:import-types')->weekly()->at('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sde:import-blueprints')->weekly()->at('05:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('characters:refresh-data')->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Schedule::command('esi:refresh-tokens')->everyTenMinutes()
//     ->withoutOverlapping()
//     ->onOneServer();

// Region order sweeps (market queue). Jita (The Forge) is checked every 15
// minutes but only sweeps every 15 minutes while its last complete sweep
// took under 10 minutes; otherwise every 30 (see
// MarketOrderSweepService::isDue). The other trade hubs sweep hourly. A
// region with a sweep still running is skipped by the job itself.
Schedule::command('market:fetch-region-orders --region='.MarketService::DEFAULT_REGION_ID)
    ->everyFifteenMinutes()
    ->when(fn () => app(MarketOrderSweepService::class)->isDue(MarketService::DEFAULT_REGION_ID))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('market:fetch-region-orders --exclude='.MarketService::DEFAULT_REGION_ID)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Schedule::command('market:fetch-region-history')->everySixHours()
//    ->withoutOverlapping()
//     ->onOneServer();

Schedule::command('market:import-daily-history')->everyThreeHours()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('contracts:import')->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Runs slightly under the 1200s (20 min) dashboard cache TTL so the cache
// stays warm without recomputing more often than necessary.
Schedule::command('dashboard:refresh')->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('industry:refresh')->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('assets:refresh')->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('esi:import-market-groups')->cron('0 3 1,15 * *')
    ->withoutOverlapping()
    ->onOneServer();

// Safety net: each completed region sweep already queues precompute for
// that region (at most every 30 minutes per region).
Schedule::command('manufacturing:precompute-costs')->everyFourHours()
    ->withoutOverlapping()
    ->onOneServer();

// SQL failed-job retention is opt-in. Capture representative diagnostics and
// enable FAILED_JOBS_RETENTION_ENABLED only after the retention period is set.
Schedule::command('queue:prune-failed')->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Retain active/recent market and public-contract snapshots; dry runs remain
// available manually without --write. SNAPSHOT_RETENTION_ENABLED can pause deletes.
Schedule::command('market:snapshots-prune --write')->daily()
    ->withoutOverlapping()
    ->onOneServer();

// ESI diagnostics: drain the Redis buffer into MySQL, and expire old rows.
// Both are no-ops until ESI_DIAGNOSTICS_ENABLED / ESI_DIAGNOSTICS_SINK=redis.
Schedule::command('esi:diagnostics-flush')->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('esi:diagnostics-prune')->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

// Web push: queue a detection pass for every user with a subscribed device. It
// reads data the refresh jobs above already cache, so it adds no ESI traffic.
Schedule::command('notifications:dispatch')->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Demo account: regenerate its three characters and data daily. No-op unless
// DEMO_ENABLED is set. Its ESI traffic is generated locally, never sent.
Schedule::command('demo:reset')->dailyAt(config('demo.reset_time', '03:30'))
    ->withoutOverlapping()
    ->onOneServer();
