<?php

namespace App\Services\Heavy;

use App\Models\CorporationBuildProgram;
use App\Models\CorporationBuildProgramConfig;
use App\Models\User;
use App\Services\CorporationBuildProgramService;
use App\Services\EsiService;

/**
 * Progress and asset locations of a corporate build program, from the
 * corporation's assets fetched with the enabling director's token. Shared by
 * the old /api/corporate/build-programs/{program}/{progress,asset-locations}
 * routes and /api/v1.
 */
class CorporateBuildProgramProgressService
{
    public function __construct(
        private EsiService $esi,
        private CorporationBuildProgramService $programs,
        private BuildProgramProgressCalculator $calculator,
        private AssetLocationResolver $locations,
    ) {}

    /**
     * The active config that gives the user access to the program.
     *
     * The corporation must have an active config reachable through one of the
     * user's characters and equal to the program's corporation (else 404, so
     * other corporations' programs are not revealed); the user also needs a
     * character in that corporation (else 403, in practice implied by the
     * first check).
     *
     * @throws HeavyServiceException 404 or 403
     */
    public function configFor(User $user, CorporationBuildProgram $program): CorporationBuildProgramConfig
    {
        $config = $this->programs->getConfigForUser((int) $user->id);
        if (! $config || $config->corporation_id !== $program->corporation_id) {
            throw new HeavyServiceException(404, 'Not found');
        }

        if (! $this->programs->canUserView($user, $program->corporation_id)) {
            throw new HeavyServiceException(403, 'Access denied');
        }

        return $config;
    }

    /**
     * @param  list<int>  $inputFilter  root location ids for materials; empty counts everything
     * @param  list<int>  $outputFilter  root location ids for finished products; empty counts everything
     * @return array<string, mixed> character_name + the calculator's summary
     *
     * @throws HeavyServiceException 400 when the director character is gone
     */
    public function progress(CorporationBuildProgramConfig $config, CorporationBuildProgram $program, array $inputFilter = [], array $outputFilter = []): array
    {
        $directorCharacter = $config->character;
        if (! $directorCharacter) {
            throw new HeavyServiceException(400, 'Director character no longer available');
        }

        $corpAssets = $this->esi->getCorporationAssets($directorCharacter, $config->corporation_id);
        $assetMap = $this->locations->assetMap($corpAssets);

        // Materials count at the input locations, finished products at the output locations.
        $inputQuantities = [];
        $outputQuantities = [];
        $inputFilterActive = ! empty($inputFilter);
        $outputFilterActive = ! empty($outputFilter);

        foreach ($corpAssets as $asset) {
            $typeId = $asset['type_id'] ?? 0;
            $qty = $asset['quantity'] ?? 0;
            if (! $typeId || $qty <= 0) {
                continue;
            }

            $rootId = $this->locations->rootLocationId($asset, $assetMap);

            if (! $inputFilterActive || in_array($rootId, $inputFilter, true)) {
                $inputQuantities[$typeId] = ($inputQuantities[$typeId] ?? 0) + $qty;
            }
            if (! $outputFilterActive || in_array($rootId, $outputFilter, true)) {
                $outputQuantities[$typeId] = ($outputQuantities[$typeId] ?? 0) + $qty;
            }
        }

        return ['character_name' => $directorCharacter->name]
            + $this->calculator->summarise($program->steps ?? [], $program->settings ?? [], $inputQuantities, $outputQuantities);
    }

    /**
     * Distinct locations of the corporation's assets. Empty when the director
     * character is gone.
     *
     * @return list<array{location_id: int, name: string, solar_system_id: ?int, solar_system_name: ?string}>
     */
    public function assetLocations(User $user, CorporationBuildProgramConfig $config): array
    {
        $directorCharacter = $config->character;
        if (! $directorCharacter) {
            return [];
        }

        // Characters with the structure scope can resolve structure names.
        $structureCharacters = $user->characters->filter(
            fn ($c) => $c->hasScope('esi-universe.read_structures.v1')
        )->values()->all();

        $corpAssets = $this->esi->getCorporationAssets($directorCharacter, $config->corporation_id);

        $rootLocationIds = [];
        $this->locations->collectRoots($corpAssets, $rootLocationIds);

        return $this->locations->describe(array_keys($rootLocationIds), $structureCharacters);
    }
}
