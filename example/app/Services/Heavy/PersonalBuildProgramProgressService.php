<?php

namespace App\Services\Heavy;

use App\Models\BuildProgram;
use App\Models\Character;
use App\Models\User;
use App\Services\Esi\EsiRequestException;
use App\Services\EsiService;

/**
 * Progress and asset locations of a personal build program, from the assets
 * of all the owner's characters. Shared by the old
 * /api/build-programs/{program}/{progress,asset-locations} routes and /api/v1.
 *
 * Ownership is not checked here: the old controller compares user ids and
 * v1 uses BuildProgramPolicy.
 */
class PersonalBuildProgramProgressService
{
    public function __construct(
        private EsiService $esi,
        private BuildProgramProgressCalculator $calculator,
        private AssetLocationResolver $locations,
    ) {}

    /**
     * @param  list<int>  $locationFilter  root location ids; empty counts everything
     * @return array<string, mixed> character_names + the calculator's summary
     *
     * @throws HeavyServiceException 400 when the user has no characters
     */
    public function progress(User $user, BuildProgram $program, array $locationFilter = []): array
    {
        $characters = $user->characters ?? collect();
        if ($characters->isEmpty()) {
            throw new HeavyServiceException(400, 'No characters linked');
        }

        $filterActive = ! empty($locationFilter);

        // Aggregate asset quantities across ALL the user's characters.
        $assetQuantities = [];
        $characterNames = [];
        foreach ($characters as $character) {
            if ($character->is_esi_active === false) {
                continue;
            }
            $assets = $this->assetsOrNull($character);
            if ($assets === null) {
                continue;
            }
            $characterNames[] = $character->name;
            $assetMap = $filterActive ? $this->locations->assetMap($assets) : [];

            foreach ($assets as $asset) {
                if ($filterActive) {
                    $rootId = $this->locations->rootLocationId($asset, $assetMap);
                    if (! in_array($rootId, $locationFilter, true)) {
                        continue;
                    }
                }
                $typeId = $asset['type_id'] ?? 0;
                $qty = $asset['quantity'] ?? 0;
                if ($typeId && $qty > 0) {
                    $assetQuantities[$typeId] = ($assetQuantities[$typeId] ?? 0) + $qty;
                }
            }
        }

        return ['character_names' => $characterNames]
            + $this->calculator->summarise($program->steps ?? [], $program->settings ?? [], $assetQuantities, $assetQuantities);
    }

    /**
     * Distinct locations where the user's characters hold items.
     *
     * @return list<array{location_id: int, name: string, solar_system_id: ?int, solar_system_name: ?string}>
     */
    public function assetLocations(User $user): array
    {
        $characters = ($user->characters ?? collect())->filter(fn ($c) => $c->is_esi_active !== false)->values();
        if ($characters->isEmpty()) {
            return [];
        }

        // Characters that can resolve player-owned structure names via ESI.
        $structureCharacters = $characters->filter(
            fn ($c) => $c->hasScope('esi-universe.read_structures.v1')
        )->values()->all();

        $rootLocationIds = [];
        foreach ($characters as $character) {
            $assets = $this->assetsOrNull($character);
            if ($assets !== null) {
                $this->locations->collectRoots($assets, $rootLocationIds);
            }
        }

        return $this->locations->describe(array_keys($rootLocationIds), $structureCharacters);
    }

    /**
     * A character whose token was revoked or that was unlinked mid-request has
     * no usable assets; other ESI failures still propagate.
     */
    private function assetsOrNull(Character $character): ?array
    {
        try {
            return $this->esi->getCharacterAssets($character);
        } catch (EsiRequestException $e) {
            if ($e->outcome === 'credential') {
                return null;
            }
            throw $e;
        }
    }
}
