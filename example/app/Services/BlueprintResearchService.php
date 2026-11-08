<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\MarketGroup;

class BlueprintResearchService
{
    public function __construct(private EsiService $esiService) {}

    /**
     * Get all T1 (meta group 1) blueprints with ownership info.
     *
     * @return array{blueprints: \Illuminate\Support\Collection, marketGroups: \Illuminate\Support\Collection, hasBlueprintData: bool, charactersWithoutScope: array}
     */
    public function getBlueprintResearchData(
        \App\Models\User $user,
        ?string $search = null,
        ?int $marketGroupId = null,
        string $sortBy = 'name',
        string $sortDir = 'asc',
    ): array {
        $hasBlueprintData = $this->userHasBlueprintScope($user);
        $ownedBlueprints = $hasBlueprintData ? $this->getOwnedBlueprints($user) : [];
        $charactersWithoutScope = $this->getCharactersWithoutScope($user);

        $query = EveType::query()
            ->join('blueprint_activities', 'eve_types.type_id', '=', 'blueprint_activities.blueprint_type_id')
            ->leftJoin('market_groups', 'eve_types.market_group_id', '=', 'market_groups.market_group_id')
            ->where('blueprint_activities.activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->whereNull('eve_types.meta_group_id')
            ->where('eve_types.published', true)
            ->select([
                'eve_types.type_id',
                'eve_types.name',
                'eve_types.market_group_id',
                'market_groups.name as market_group_name',
            ]);

        if ($search) {
            $query->where('eve_types.name', 'like', "%{$search}%");
        }

        if ($marketGroupId) {
            $query->where('eve_types.market_group_id', $marketGroupId);
        }

        $sortColumn = match ($sortBy) {
            'owned' => 'eve_types.name',
            'market_group' => 'market_groups.name',
            default => 'eve_types.name',
        };
        $query->orderBy($sortColumn, $sortDir === 'desc' ? 'desc' : 'asc');

        $blueprints = $query->get();

        if ($sortBy === 'owned') {
            $blueprints = $blueprints->sortBy(function ($blueprint) use ($ownedBlueprints) {
                return isset($ownedBlueprints[$blueprint->type_id]) ? 0 : 1;
            }, SORT_REGULAR, $sortDir === 'desc')->values();
        }

        $blueprints->transform(function ($blueprint) use ($ownedBlueprints) {
            $owned = $ownedBlueprints[$blueprint->type_id] ?? null;

            $blueprint->owned = $owned !== null;
            $blueprint->me = $owned['material_efficiency'] ?? null;
            $blueprint->te = $owned['time_efficiency'] ?? null;
            $blueprint->owner_names = $owned['owner_names'] ?? [];

            return $blueprint;
        });

        $marketGroups = $this->getAvailableMarketGroups();

        return [
            'blueprints' => $blueprints,
            'marketGroups' => $marketGroups,
            'hasBlueprintData' => $hasBlueprintData,
            'charactersWithoutScope' => $charactersWithoutScope,
        ];
    }

    /**
     * Check if any of the user's characters have the blueprint scope.
     */
    public function userHasBlueprintScope(\App\Models\User $user): bool
    {
        foreach ($user->characters as $character) {
            if ($character->hasScope('esi-characters.read_blueprints.v1')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all blueprints owned by the user across all characters, keyed by type_id.
     * When multiple copies exist, the highest ME/TE values are kept.
     * Tracks which characters own each blueprint.
     */
    public function getOwnedBlueprints(\App\Models\User $user): array
    {
        $owned = [];

        foreach ($user->characters as $character) {
            if ($character->is_esi_active === false || ! $character->hasScope('esi-characters.read_blueprints.v1')) {
                continue;
            }

            $blueprints = $this->esiService->getCharacterBlueprints($character);

            foreach ($blueprints as $bp) {
                $typeId = $bp['type_id'];
                $me = $bp['material_efficiency'] ?? 0;
                $te = $bp['time_efficiency'] ?? 0;

                if (! isset($owned[$typeId])) {
                    $owned[$typeId] = [
                        'material_efficiency' => $me,
                        'time_efficiency' => $te,
                        'owner_names' => [$character->name],
                    ];
                } else {
                    if (! in_array($character->name, $owned[$typeId]['owner_names'])) {
                        $owned[$typeId]['owner_names'][] = $character->name;
                    }

                    if ($me > $owned[$typeId]['material_efficiency']
                        || ($me === $owned[$typeId]['material_efficiency'] && $te > $owned[$typeId]['time_efficiency'])
                    ) {
                        $owned[$typeId]['material_efficiency'] = $me;
                        $owned[$typeId]['time_efficiency'] = $te;
                    }
                }
            }
        }

        return $owned;
    }

    /**
     * Get characters that do not have the blueprint read scope.
     *
     * @return array<array{character_id: int, name: string}>
     */
    public function getCharactersWithoutScope(\App\Models\User $user): array
    {
        $missing = [];

        foreach ($user->characters as $character) {
            if (! $character->hasScope('esi-characters.read_blueprints.v1')) {
                $missing[] = [
                    'character_id' => $character->character_id,
                    'name' => $character->name,
                ];
            }
        }

        return $missing;
    }

    /**
     * Get list of market groups that contain T1 blueprints, for filter dropdown.
     */
    public function getAvailableMarketGroups(): \Illuminate\Support\Collection
    {
        return MarketGroup::query()
            ->whereHas('types', function ($q) {
                $q->where('meta_group_id', EveType::META_GROUP_TECH_I)
                    ->where('published', true)
                    ->whereIn('type_id', function ($sub) {
                        $sub->select('blueprint_type_id')
                            ->from('blueprint_activities')
                            ->where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING);
                    });
            })
            ->orderBy('name')
            ->get(['market_group_id', 'name']);
    }
}
