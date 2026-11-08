<?php

namespace App\Services\Heavy;

use App\Models\SdeSolarSystem;
use App\Models\User;
use App\Services\EsiService;

/**
 * Where one of the user's characters is right now (live ESI, cache ignored).
 * Shared by the old GET /api/wholemap/current-location and GET /api/v1/wholemap/location.
 */
class CurrentLocationService
{
    public const LOCATION_SCOPE = 'esi-location.read_location.v1';

    public function __construct(private EsiService $esi) {}

    /**
     * Also stores the system name on the character as `cached_location`.
     *
     * @param  int|string|null  $characterId  one of the user's characters; the main character when empty
     * @return array{solar_system_id: int, solar_system_name: string, security: ?float, security_class: ?string, region_id: ?int}
     *
     * @throws HeavyServiceException 422 when the character cannot be used, 502 when ESI cannot answer
     */
    public function current(User $user, int|string|null $characterId = null): array
    {
        if ($characterId) {
            $character = $user->characters()->where('character_id', (int) $characterId)->first();

            if (! $character) {
                throw new HeavyServiceException(422, 'Character not found.');
            }
        } else {
            $character = $user->mainCharacter;
        }

        if (! $character) {
            throw new HeavyServiceException(422, 'No main character linked.');
        }

        if (! $character->hasScope(self::LOCATION_SCOPE)) {
            throw new HeavyServiceException(422, 'Location scope not granted.');
        }

        $positionData = $this->esi->fetchPilotCurrentPosition($character, ignoreCache: true);

        if (! $positionData || ! isset($positionData['solar_system_id'])) {
            throw new HeavyServiceException(502, 'Unable to fetch location.');
        }

        $solarSystemId = $positionData['solar_system_id'];

        // System info from the SDE first, then ESI.
        $sdeSystem = SdeSolarSystem::find($solarSystemId);
        $systemName = $sdeSystem?->solar_system_name;

        if (! $systemName) {
            $esiSystem = $this->esi->getSystem($solarSystemId);
            $systemName = $esiSystem['name'] ?? null;
        }

        if (! $systemName) {
            throw new HeavyServiceException(502, 'Unable to resolve system name.');
        }

        if ($character->cached_location !== $systemName) {
            $character->update(['cached_location' => $systemName]);
        }

        return [
            'solar_system_id' => $solarSystemId,
            'solar_system_name' => $systemName,
            'security' => $sdeSystem?->security,
            'security_class' => $sdeSystem?->security_class,
            'region_id' => $sdeSystem?->region_id,
        ];
    }
}
