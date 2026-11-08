<?php

namespace App\Services\Reference;

use App\Services\EsiService;

/**
 * Industry system cost indices from ESI /industry/systems/ (cached 6 h by
 * EsiService). Shared by the planners' system-cost-index routes and
 * /api/v1/systems/{id}/cost-indices.
 */
class SystemCostIndexService
{
    /** Activities ESI reports, in the order v1 returns them. */
    public const ACTIVITIES = [
        'manufacturing',
        'researching_time_efficiency',
        'researching_material_efficiency',
        'copying',
        'invention',
        'reaction',
    ];

    public function __construct(private EsiService $esi) {}

    /**
     * Cost index per activity for one system.
     *
     * Returns null when ESI is unavailable, [] when ESI does not list the
     * system, else [activity => cost_index]. If ESI ever listed an activity
     * twice, the last entry wins.
     *
     * @return array<string, float>|null
     */
    public function forSystem(int $solarSystemId): ?array
    {
        $indices = $this->esi->getIndustrySystemCostIndices();
        if (! $indices) {
            return null;
        }

        foreach ($indices as $system) {
            if (($system['solar_system_id'] ?? 0) === $solarSystemId) {
                $byActivity = [];
                foreach ($system['cost_indices'] ?? [] as $ci) {
                    $byActivity[$ci['activity']] = (float) $ci['cost_index'];
                }

                return $byActivity;
            }
        }

        return [];
    }

    /** One activity's index; 0.0 when ESI is down or has no entry. */
    public function index(int $solarSystemId, string $activity): float
    {
        return $this->forSystem($solarSystemId)[$activity] ?? 0.0;
    }
}
