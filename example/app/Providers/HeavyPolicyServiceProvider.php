<?php

namespace App\Providers;

use App\Models\MoonScanSystem;
use App\Policies\MoonScanSystemPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies for the heavy-pages endpoints on /api/v1 (routes/api_v1/heavy.php)
 * whose model no other provider registers. BuildProgram, CorporationBuildProgram
 * and StationConfig are in ProgramsPolicyServiceProvider; WormholeConnection is
 * in PlanningPolicyServiceProvider.
 */
class HeavyPolicyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(MoonScanSystem::class, MoonScanSystemPolicy::class);
    }
}
