<?php

namespace App\Services\Planning;

use App\Models\InventionPlan;
use App\Models\ReactionPlan;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Saved invention and reaction plans. Shared by the legacy planner routes and
 * /api/v1. Callers check ownership and validate input.
 */
class PlanStore
{
    public function paginateInvention(User $user, int $page, int $perPage): LengthAwarePaginator
    {
        return $user->inventionPlans()->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
    }

    public function paginateReaction(User $user, int $page, int $perPage): LengthAwarePaginator
    {
        return $user->reactionPlans()->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $v  validated input
     */
    public function createInvention(User $user, array $v): InventionPlan
    {
        return $user->inventionPlans()->create([
            'name' => $v['name'],
            't2_type_id' => $v['t2_type_id'],
            't2_type_name' => $v['t2_type_name'],
            'decryptor_key' => $v['decryptor_key'] ?? 'none',
            't1_me' => $v['t1_me'] ?? 0,
            't1_te' => $v['t1_te'] ?? 0,
            'encryption_skill' => $v['encryption_skill'] ?? 0,
            'science1_skill' => $v['science1_skill'] ?? 0,
            'science2_skill' => $v['science2_skill'] ?? 0,
            'invention_runs' => $v['invention_runs'] ?? 1,
            'structure_type' => $v['structure_type'] ?? 'none',
            'rig_me' => $v['rig_me'] ?? 'none',
            'rig_te' => $v['rig_te'] ?? 'none',
            'system_security' => $v['system_security'] ?? 'highsec',
            'facility_tax' => $v['facility_tax'] ?? 0,
            'solar_system_id' => $v['solar_system_id'] ?? null,
            'station_id' => $v['station_id'] ?? null,
            'price_region_id' => $v['price_region_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $v
     */
    public function updateInvention(InventionPlan $plan, array $v): InventionPlan
    {
        $plan->update($v);

        return $plan->fresh();
    }

    /**
     * @param  array<string, mixed>  $v
     */
    public function createReaction(User $user, array $v): ReactionPlan
    {
        return $user->reactionPlans()->create([
            'name' => $v['name'],
            'entries' => $v['entries'],
            'default_runs_per_slot' => $v['default_runs_per_slot'] ?? 240,
            'rig_bonus' => $v['rig_bonus'] ?? 0,
            'structure_type' => $v['structure_type'] ?? 'athanor',
            'rig_tier' => $v['rig_tier'] ?? 'none',
            'security_space' => $v['security_space'] ?? 'lowsec',
            'facility_tax' => $v['facility_tax'] ?? 0,
            'station_id' => $v['station_id'] ?? null,
            'solar_system_id' => $v['solar_system_id'] ?? null,
            'price_region_id' => $v['price_region_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $v
     */
    public function updateReaction(ReactionPlan $plan, array $v): ReactionPlan
    {
        $plan->update($v);

        return $plan->fresh();
    }

    public function delete(InventionPlan|ReactionPlan $plan): void
    {
        $plan->delete();
    }
}
