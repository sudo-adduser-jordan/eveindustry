<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The numbers on the dashboard. Both blocks come from caches that
 * `dashboard:refresh` keeps warm; a cold cache is computed on the spot.
 */
class DashboardService
{
    public function __construct(
        protected IndustryService $industryService,
        protected PlanetaryService $planetaryService,
    ) {}

    /**
     * @return array{in_progress: int, ready_jobs: int, available_slots: int}
     */
    public function industryStats(int $userId): array
    {
        $cached = Cache::get("dashboard.industry_stats.{$userId}");
        if ($cached !== null) {
            return $cached;
        }

        try {
            $availability = $this->industryService->getAvailabilityForUser($userId);
            $totals = $availability['totals'] ?? [];

            $inProgress = 0;
            $readyJobs = 0;
            $availableSlots = 0;

            foreach (['manufacturing', 'research', 'reactions'] as $type) {
                $inProgress += $totals[$type]['active'] ?? 0;
                $availableSlots += $totals[$type]['available'] ?? 0;
            }

            // Count ready-for-delivery jobs from character slot data
            foreach ($availability['characters'] ?? [] as $charData) {
                foreach (['manufacturing', 'research', 'reactions'] as $type) {
                    foreach ($charData[$type]['jobs'] ?? [] as $job) {
                        if (! empty($job['ready_for_delivery'])) {
                            $readyJobs++;
                        }
                    }
                }
            }

            return [
                'in_progress' => $inProgress,
                'ready_jobs' => $readyJobs,
                'available_slots' => $availableSlots,
            ];
        } catch (\Throwable $e) {
            Log::warning('Dashboard: Failed to load industry stats: '.$e->getMessage());

            return [
                'in_progress' => 0,
                'ready_jobs' => 0,
                'available_slots' => 0,
            ];
        }
    }

    /**
     * @return array{total_planets: int, expired_extractors: int, full_storage: int}
     */
    public function planetaryStats(int $userId): array
    {
        $cached = Cache::get("dashboard.pi_stats.{$userId}");
        if ($cached !== null) {
            return $cached;
        }

        try {
            $piData = $this->planetaryService->getPlanetaryDataForUser($userId);
            $alerts = $piData['alerts'] ?? [];

            $expiredExtractors = 0;
            $fullStorage = 0;

            foreach ($alerts as $alert) {
                if (($alert['type'] ?? '') === 'extractor_expired') {
                    $expiredExtractors++;
                }
            }

            // Check storage usage across all planets
            foreach ($piData['characters'] ?? [] as $charData) {
                foreach ($charData['planets'] ?? [] as $planet) {
                    foreach ($planet['pins'] ?? [] as $pin) {
                        if (isset($pin['storage']) && ($pin['storage']['usage_percent'] ?? 0) >= 90) {
                            $fullStorage++;
                        }
                    }
                }
            }

            return [
                'total_planets' => $piData['summary']['total_planets'] ?? 0,
                'expired_extractors' => $expiredExtractors,
                'full_storage' => $fullStorage,
            ];
        } catch (\Throwable $e) {
            Log::warning('Dashboard: Failed to load PI stats: '.$e->getMessage());

            return [
                'total_planets' => 0,
                'expired_extractors' => 0,
                'full_storage' => 0,
            ];
        }
    }
}
