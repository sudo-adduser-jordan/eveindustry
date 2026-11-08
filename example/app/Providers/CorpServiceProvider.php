<?php

namespace App\Providers;

use App\Policies\CorporationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Gates for the corporation endpoints on /api/v1 (routes/api_v1/corp.php).
 */
class CorpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define(CorporationPolicy::VIEW_ASSETS, [CorporationPolicy::class, 'viewAssets']);
        Gate::define(CorporationPolicy::VIEW_DONATIONS, [CorporationPolicy::class, 'viewDonations']);
        Gate::define(CorporationPolicy::VIEW_ESS, [CorporationPolicy::class, 'viewEss']);
        Gate::define(CorporationPolicy::VIEW_PROJECTS, [CorporationPolicy::class, 'viewProjects']);
        Gate::define(CorporationPolicy::VIEW_STRUCTURE_TIMERS, [CorporationPolicy::class, 'viewStructureTimers']);
    }
}
