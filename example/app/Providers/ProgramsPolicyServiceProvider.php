<?php

namespace App\Providers;

use App\Models\BuildProgram;
use App\Models\CorporationBuildProgram;
use App\Models\IndustryStockItem;
use App\Models\StationConfig;
use App\Policies\BuildProgramPolicy;
use App\Policies\CorporationBuildProgramPolicy;
use App\Policies\IndustryStockItemPolicy;
use App\Policies\StationConfigPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Model policies for the write endpoints in routes/api_v1/writes_programs.php.
 */
class ProgramsPolicyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(BuildProgram::class, BuildProgramPolicy::class);
        Gate::policy(CorporationBuildProgram::class, CorporationBuildProgramPolicy::class);
        Gate::policy(StationConfig::class, StationConfigPolicy::class);
        Gate::policy(IndustryStockItem::class, IndustryStockItemPolicy::class);
    }
}
