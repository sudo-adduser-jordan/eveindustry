<?php

namespace App\Services\Programs;

use App\Models\BlueprintActivity;
use App\Models\BuildProgram;
use App\Models\User;
use App\Services\InventionPlannerService;
use App\Services\ManufacturingCalculatorService;
use App\Services\ReactionsCalculatorService;
use Illuminate\Support\Collection;

/**
 * Creates, changes and deletes a user's personal build programs.
 *
 * Shared by the old /api/build-programs write routes and the /api/v1 ones.
 * The step-building helpers (nestSteps, reactionPlanSteps, mergeFields) are
 * also used by CorporationBuildProgramWriter, which only differs in who owns
 * the program.
 */
class BuildProgramWriter
{
    public function __construct(
        protected InventionPlannerService $planner,
        protected ReactionsCalculatorService $reactionsCalculator,
    ) {}

    /**
     * Create a program from calculator steps.
     *
     * Reads $validated['t2_type_id'] and ['t2_type_name'] directly, as the old
     * route always did: callers must pass the keys (null is fine).
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, array $validated): BuildProgram
    {
        $settings = $validated['settings'] ?? [];
        $nested = $this->nestSteps($validated['steps'], $this->reactionMeBonus($settings));

        return $user->buildPrograms()->create([
            'name' => $validated['name'],
            't2_type_id' => $validated['t2_type_id'],
            't2_type_name' => $validated['t2_type_name'],
            'steps' => $nested,
            'settings' => $validated['settings'] ?? [],
        ]);
    }

    /**
     * Create a reaction program from reaction plan entries, or null when none
     * of the entries match a known reaction formula.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createFromReactionPlan(User $user, array $validated): ?BuildProgram
    {
        $plan = $this->reactionPlanSteps($validated);
        if ($plan === null) {
            return null;
        }

        return $user->buildPrograms()->create([
            'name' => $validated['name'],
            'program_type' => 'reaction',
            't2_type_id' => $plan['first_type_id'],
            't2_type_name' => $plan['first_type_name'],
            'steps' => $plan['steps'],
            'settings' => $plan['settings'],
        ]);
    }

    /**
     * Merge the user's own programs into a new one; the sources are left
     * unchanged. Returns null when fewer than two of the ids are the user's.
     *
     * @param  array<string, mixed>  $validated  program_ids and name
     */
    public function merge(User $user, array $validated): ?BuildProgram
    {
        $programs = BuildProgram::where('user_id', $user->id)
            ->whereIn('id', $validated['program_ids'])
            ->get();

        if ($programs->count() < 2) {
            return null;
        }

        $fields = $this->mergeFields($programs, $validated['program_ids']);

        return $user->buildPrograms()->create([
            'name' => $validated['name'],
            'program_type' => $fields['program_type'],
            't2_type_id' => null,
            't2_type_name' => null,
            'steps' => $fields['steps'],
            'settings' => $fields['settings'],
        ]);
    }

    public function rename(BuildProgram $program, string $name): BuildProgram
    {
        $program->update(['name' => $name]);

        return $program;
    }

    /**
     * @param  array<int, mixed>  $locationIds
     */
    public function setLocationFilter(BuildProgram $program, array $locationIds): BuildProgram
    {
        $settings = $program->settings ?? [];
        $settings['location_ids'] = array_map('intval', $locationIds);

        $program->update(['settings' => $settings]);

        return $program;
    }

    public function delete(BuildProgram $program): void
    {
        $program->delete();
    }

    /**
     * Resolve each step's materials into child steps and tag the step with
     * its blueprint (manufacturing first, then reaction).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    public function nestSteps(array $steps, ?float $reactionMeBonus): array
    {
        $nestedSteps = [];
        foreach ($steps as $step) {
            $materials = $step['materials'] ?? [];
            $children = ! empty($materials)
                ? $this->planner->resolveFullMaterialChain($materials, 10, 0.0, [], $reactionMeBonus)
                : [];
            $step['children'] = $children;

            $typeId = $step['type_id'];
            $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->whereProducesType($typeId)
                ->first();
            if (! $activity) {
                $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
                    ->whereProducesType($typeId)
                    ->first();
            }
            if ($activity) {
                $step['activity_type'] = $activity->activity_id === BlueprintActivity::ACTIVITY_REACTION ? 'reaction' : 'manufacturing';
                $step['blueprint_type_id'] = $activity->blueprint_type_id;
            }

            $nestedSteps[] = $step;
        }

        return $nestedSteps;
    }

    /**
     * Build one top-level step per reaction entry (with its intermediate
     * reactions as children). Null when no entry has a known formula.
     *
     * @param  array<string, mixed>  $validated
     * @return array{steps: array<int, array<string, mixed>>, first_type_id: ?int, first_type_name: ?string, settings: array<string, mixed>}|null
     */
    public function reactionPlanSteps(array $validated): ?array
    {
        $settings = $validated['settings'] ?? [];
        $rigTier = $settings['reaction_rig_me'] ?? 'none';
        $securitySpace = $settings['reaction_system_security'] ?? 'lowsec';
        $rigBonus = ReactionsCalculatorService::resolveRigMeBonus($rigTier, $securitySpace);

        $nestedSteps = [];
        $firstProductTypeId = null;
        $firstProductName = null;

        foreach ($validated['entries'] as $entry) {
            $formulaTypeId = $entry['formula_type_id'];
            $totalRuns = $entry['slots'] * $entry['runs_per_slot'];

            $activity = BlueprintActivity::where('blueprint_type_id', $formulaTypeId)
                ->where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
                ->first();

            if (! $activity) {
                continue;
            }

            $productTypeId = null;
            $productName = $entry['formula_name'] ?? 'Unknown';
            $portionSize = 1;

            foreach ($activity->products ?? [] as $product) {
                $productTypeId = $product['type_id'] ?? null;
                $productName = $product['name'] ?? $productName;
                $portionSize = max(1, (int) ($product['quantity'] ?? 1));
                break;
            }

            if (! $productTypeId) {
                continue;
            }

            if ($firstProductTypeId === null) {
                $firstProductTypeId = $productTypeId;
                $firstProductName = $productName;
            }

            $adjustedMaterials = $this->reactionsCalculator->calculateMaterials(
                $activity->materials ?? [],
                $totalRuns,
                $rigBonus
            );

            $children = ! empty($adjustedMaterials)
                ? $this->planner->resolveFullMaterialChain($adjustedMaterials, 10, 0.0, [], $rigBonus)
                : [];

            $nestedSteps[] = [
                'type_id' => $productTypeId,
                'name' => $productName,
                'quantity_required' => $totalRuns * $portionSize,
                'runs_needed' => $totalRuns,
                'activity_type' => 'reaction',
                'blueprint_type_id' => $formulaTypeId,
                'materials' => $adjustedMaterials,
                'children' => $children,
            ];
        }

        if (empty($nestedSteps)) {
            return null;
        }

        return [
            'steps' => $nestedSteps,
            'first_type_id' => $firstProductTypeId,
            'first_type_name' => $firstProductName,
            'settings' => $settings,
        ];
    }

    /**
     * Steps, settings and type for a merged program. Sources are walked in
     * the order of $programIds; settings of later programs win; a mix of
     * program types becomes "manufacturing".
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $programs  BuildProgram or CorporationBuildProgram models
     * @param  array<int, int|string>  $programIds
     * @return array{steps: array<int, mixed>, settings: array<string, mixed>, program_type: string}
     */
    public function mergeFields(Collection $programs, array $programIds): array
    {
        $mergedSteps = [];
        $mergedSettings = [];
        $sections = [];
        $programType = $programs->first()->program_type ?? 'manufacturing';
        $mixedTypes = false;

        $orderedPrograms = collect($programIds)
            ->map(fn ($id) => $programs->firstWhere('id', $id))
            ->filter();

        foreach ($orderedPrograms as $program) {
            $programSteps = $program->steps ?? [];
            $sections[] = [
                'name' => $program->name,
                'step_count' => count($programSteps),
            ];

            foreach ($programSteps as $step) {
                $mergedSteps[] = $step;
            }

            if ($program->program_type !== $programType) {
                $mixedTypes = true;
            }

            $mergedSettings = array_merge($mergedSettings, $program->settings ?? []);
        }

        $mergedSettings['sections'] = $sections;

        return [
            'steps' => $mergedSteps,
            'settings' => $mergedSettings,
            'program_type' => $mixedTypes ? 'manufacturing' : $programType,
        ];
    }

    /**
     * The reaction structure ME bonus a personal program's settings imply:
     * null when no reaction structure is chosen.
     *
     * @param  array<string, mixed>  $settings
     */
    public function reactionMeBonus(array $settings): ?float
    {
        $reactionStructureType = $settings['reaction_structure_type'] ?? null;
        if ($reactionStructureType === null) {
            return null;
        }

        $rigMe = $settings['reaction_rig_me'] ?? 'none';
        $systemSecurity = $settings['reaction_system_security'] ?? 'lowsec';

        $rigBase = match ($rigMe) {
            't1' => ManufacturingCalculatorService::RIG_ME_T1,
            't2' => ManufacturingCalculatorService::RIG_ME_T2,
            default => 0.0,
        };

        $securityMultiplier = match ($systemSecurity) {
            'highsec' => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_HIGHSEC,
            'lowsec' => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_LOWSEC,
            'nullsec' => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_NULLSEC,
            default => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_HIGHSEC,
        };

        return $rigBase * $securityMultiplier;
    }
}
