<?php

namespace App\Services\Programs;

use App\Models\CorporationBuildProgram;
use App\Models\User;

/**
 * Creates, changes and deletes corporate build programs.
 *
 * Shared by the old /api/corporate/build-programs write routes and the
 * /api/v1/corporation/build-programs ones. Who may do it is decided by the
 * caller (CorporationBuildProgramService::canUserManage / the policy), not
 * here.
 */
class CorporationBuildProgramWriter
{
    public function __construct(protected BuildProgramWriter $writer) {}

    /**
     * Reads $validated['t2_type_id'] and ['t2_type_name'] directly, as the old
     * route always did: callers must pass the keys (null is fine).
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(int $corporationId, User $user, array $validated): CorporationBuildProgram
    {
        $settings = $validated['settings'] ?? [];
        $nested = $this->writer->nestSteps($validated['steps'], $this->reactionMeBonus($settings));

        return CorporationBuildProgram::create([
            'corporation_id' => $corporationId,
            'created_by_user_id' => $user->id,
            'name' => $validated['name'],
            't2_type_id' => $validated['t2_type_id'],
            't2_type_name' => $validated['t2_type_name'],
            'steps' => $nested,
            'settings' => $settings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFromReactionPlan(int $corporationId, User $user, array $validated): ?CorporationBuildProgram
    {
        $plan = $this->writer->reactionPlanSteps($validated);
        if ($plan === null) {
            return null;
        }

        return CorporationBuildProgram::create([
            'corporation_id' => $corporationId,
            'created_by_user_id' => $user->id,
            'name' => $validated['name'],
            'program_type' => 'reaction',
            't2_type_id' => $plan['first_type_id'],
            't2_type_name' => $plan['first_type_name'],
            'steps' => $plan['steps'],
            'settings' => $plan['settings'],
        ]);
    }

    /**
     * Merge the corporation's programs into a new one (sources unchanged).
     * Null when fewer than two of the ids belong to the corporation.
     *
     * @param  array<string, mixed>  $validated  program_ids and name
     */
    public function merge(int $corporationId, User $user, array $validated): ?CorporationBuildProgram
    {
        $programs = CorporationBuildProgram::where('corporation_id', $corporationId)
            ->whereIn('id', $validated['program_ids'])
            ->get();

        if ($programs->count() < 2) {
            return null;
        }

        $fields = $this->writer->mergeFields($programs, $validated['program_ids']);

        return CorporationBuildProgram::create([
            'corporation_id' => $corporationId,
            'created_by_user_id' => $user->id,
            'name' => $validated['name'],
            'program_type' => $fields['program_type'],
            't2_type_id' => null,
            't2_type_name' => null,
            'steps' => $fields['steps'],
            'settings' => $fields['settings'],
        ]);
    }

    public function rename(CorporationBuildProgram $program, string $name): CorporationBuildProgram
    {
        $program->update(['name' => $name]);

        return $program;
    }

    /**
     * @param  array<int, mixed>  $inputLocationIds  where materials are counted
     * @param  array<int, mixed>  $outputLocationIds  where finished products are counted
     */
    public function setLocationFilter(CorporationBuildProgram $program, array $inputLocationIds, array $outputLocationIds): CorporationBuildProgram
    {
        $settings = $program->settings ?? [];
        $settings['input_location_ids'] = array_map('intval', $inputLocationIds);
        $settings['output_location_ids'] = array_map('intval', $outputLocationIds);

        $program->update(['settings' => $settings]);

        return $program;
    }

    public function delete(CorporationBuildProgram $program): void
    {
        $program->delete();
    }

    /**
     * The corporate rule differs from the personal one: it reads the stored
     * rig bonus and is 0 without a reaction structure.
     *
     * @param  array<string, mixed>  $settings
     */
    public function reactionMeBonus(array $settings): float
    {
        $structureType = $settings['reaction_structure_type'] ?? null;
        $rigBonus = (float) ($settings['reaction_rig_bonus'] ?? 0);

        return match ($structureType) {
            'athanor', 'tatara' => $rigBonus,
            default => 0.0,
        };
    }
}
