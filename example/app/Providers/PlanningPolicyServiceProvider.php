<?php

namespace App\Providers;

use App\Models\InventionPlan;
use App\Models\MoonScanResult;
use App\Models\ReactionPlan;
use App\Models\StationConfig;
use App\Models\WholemapSignature;
use App\Models\WholemapSystemNote;
use App\Models\WormholeConnection;
use App\Policies\HiddenOwnedRecordPolicy;
use App\Policies\MoonScanResultPolicy;
use App\Policies\WholemapRecordPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Model policies for the planning write endpoints on /api/v1
 * (routes/api_v1/writes_planning.php).
 */
class PlanningPolicyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(WholemapSignature::class, WholemapRecordPolicy::class);
        Gate::policy(WormholeConnection::class, WholemapRecordPolicy::class);
        Gate::policy(WholemapSystemNote::class, WholemapRecordPolicy::class);

        Gate::policy(InventionPlan::class, HiddenOwnedRecordPolicy::class);
        Gate::policy(ReactionPlan::class, HiddenOwnedRecordPolicy::class);

        Gate::policy(MoonScanResult::class, MoonScanResultPolicy::class);
    }
}
