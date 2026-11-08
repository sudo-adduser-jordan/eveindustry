<?php

namespace App\Services;

use App\Models\Character;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class IndustryService
{
    // Skill Type IDs
    public const SKILL_MASS_PRODUCTION = 3387;

    public const SKILL_ADV_MASS_PRODUCTION = 24625;

    public const SKILL_LABORATORY_OPERATION = 3406;

    public const SKILL_ADV_LABORATORY_OPERATION = 24624;

    public const SKILL_MASS_REACTIONS = 45748;

    public const SKILL_ADV_MASS_REACTIONS = 45749;

    /** Cache TTL for industry data: 20 minutes in seconds. */
    public const INDUSTRY_CACHE_TTL = 1200;

    // Activity IDs
    public const ACTIVITY_MANUFACTURING = 1;

    public const ACTIVITY_RESEARCH_TE = 3;

    public const ACTIVITY_RESEARCH_ME = 4;

    public const ACTIVITY_COPYING = 5;

    public const ACTIVITY_INVENTION = 8;

    public const ACTIVITY_REACTIONS = 9;

    public const RESEARCH_ACTIVITIES = [
        self::ACTIVITY_RESEARCH_TE,
        self::ACTIVITY_RESEARCH_ME,
        self::ACTIVITY_COPYING,
        self::ACTIVITY_INVENTION,
    ];

    public function __construct(protected EsiService $esi) {}

    /**
     * Per-instance memoized lookup of a user's characters to avoid re-running
     * the same query across the many methods that need it within one request.
     *
     * @var array<int, \Illuminate\Support\Collection>
     */
    private bool $refreshing = false;

    private array $corporationJobs = [];

    protected function charactersForUser(int $userId)
    {
        return RefreshEligibility::characters($userId, 'industry')->get();
    }

    public function getAvailabilityForUser(int $userId): array
    {
        $characters = $this->charactersForUser($userId);
        $result = [];
        $totals = [
            'manufacturing' => ['total' => 0, 'active' => 0, 'available' => 0],
            'research' => ['total' => 0, 'active' => 0, 'available' => 0],
            'reactions' => ['total' => 0, 'active' => 0, 'available' => 0],
        ];

        foreach ($characters as $character) {
            $charData = $this->getCharacterSlots($character);
            $result[] = $charData;

            foreach (['manufacturing', 'research', 'reactions'] as $type) {
                $totals[$type]['total'] += $charData[$type]['total'];
                $totals[$type]['active'] += $charData[$type]['active'];
                $totals[$type]['available'] += $charData[$type]['available'];
            }
        }

        return [
            'characters' => $result,
            'totals' => $totals,
        ];
    }

    public function getCharacterSlots(Character $character): array
    {
        $cacheKey = $this->slotsCacheKey($character);

        $build = function () use ($character) {
            $skills = $this->getCharacterSkills($character);
            $jobs = $this->getActiveJobs($character);

            // Do NOT exclude near-complete jobs from active lists; they are still in-progress but ready for delivery

            $manufacturingTotal = $this->calculateManufacturingSlots($skills);
            $researchTotal = $this->calculateResearchSlots($skills);
            $reactionsTotal = $this->calculateReactionSlots($skills);

            $manufacturingActive = $this->countJobsByActivity($jobs, [self::ACTIVITY_MANUFACTURING]);
            $researchActive = $this->countJobsByActivity($jobs, self::RESEARCH_ACTIVITIES);
            $reactionsActive = $this->countJobsByActivity($jobs, [self::ACTIVITY_REACTIONS]);

            $completingSoon = $this->getJobsCompletingSoon($jobs);

            // Get detailed jobs grouped by activity type (include near-complete so they remain visible as in-progress)
            $manufacturingJobs = $this->getDetailedJobsByActivity($jobs, [self::ACTIVITY_MANUFACTURING]);
            $researchJobs = $this->getDetailedJobsByActivity($jobs, self::RESEARCH_ACTIVITIES);
            $reactionJobs = $this->getDetailedJobsByActivity($jobs, [self::ACTIVITY_REACTIONS]);

            return [
                'character_id' => $character->character_id,
                'character_name' => $character->name,
                'portrait_url' => $character->portrait_url,
                'corporation_name' => $character->corporation_name,
                'manufacturing' => [
                    'total' => $manufacturingTotal,
                    'active' => $manufacturingActive,
                    'available' => max(0, $manufacturingTotal - $manufacturingActive),
                    'jobs' => $manufacturingJobs,
                ],
                'research' => [
                    'total' => $researchTotal,
                    'active' => $researchActive,
                    'available' => max(0, $researchTotal - $researchActive),
                    'jobs' => $researchJobs,
                ],
                'reactions' => [
                    'total' => $reactionsTotal,
                    'active' => $reactionsActive,
                    'available' => max(0, $reactionsTotal - $reactionsActive),
                    'jobs' => $reactionJobs,
                ],
                'completing_soon' => $completingSoon,
                'skills' => [
                    'mass_production' => $skills[self::SKILL_MASS_PRODUCTION] ?? 0,
                    'adv_mass_production' => $skills[self::SKILL_ADV_MASS_PRODUCTION] ?? 0,
                    'laboratory_operation' => $skills[self::SKILL_LABORATORY_OPERATION] ?? 0,
                    'adv_laboratory_operation' => $skills[self::SKILL_ADV_LABORATORY_OPERATION] ?? 0,
                    'mass_reactions' => $skills[self::SKILL_MASS_REACTIONS] ?? 0,
                    'adv_mass_reactions' => $skills[self::SKILL_ADV_MASS_REACTIONS] ?? 0,
                ],
            ];
        };

        return $this->refreshing ? $build() : Cache::remember($cacheKey, self::INDUSTRY_CACHE_TTL, $build);
    }

    private function slotsCacheKey(Character $character): string
    {
        return "industry.slots.{$character->character_id}.".sha1(json_encode([
            $character->corporation_id, $character->roles, $character->scopes, $character->is_esi_active,
            $character->corporation_id && RefreshEligibility::corporation(
                $character, $character->corporation_id, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']
            ),
            $character->corporation_id ? $this->corporationCollectors(
                $character->user_id, $character->corporation_id
            )->pluck('character_id')->all() : [],
        ]));
    }

    private function overviewCacheKey(int $userId): string
    {
        return "industry.overview.{$userId}.".sha1($this->charactersForUser($userId)->map(
            fn (Character $character) => $this->slotsCacheKey($character)
        )->implode('|'));
    }

    /** Build the replacement first; upstream failures leave the previous complete view intact. */
    public function refreshForUser(int $userId): void
    {
        $this->corporationJobs = [];
        $this->refreshing = true;
        try {
            $availability = $this->getAvailabilityForUser($userId);
            $overview = $this->buildJobsOverview($userId);
        } finally {
            $this->refreshing = false;
        }

        $characters = $this->charactersForUser($userId)->keyBy('character_id');
        foreach ($availability['characters'] as $slots) {
            if ($character = $characters->get($slots['character_id'])) {
                Cache::put($this->slotsCacheKey($character), $slots, self::INDUSTRY_CACHE_TTL);
            }
        }
        Cache::put($this->overviewCacheKey($userId), $overview, self::INDUSTRY_CACHE_TTL);
    }

    protected function calculateManufacturingSlots(array $skills): int
    {
        return 1 + ($skills[self::SKILL_MASS_PRODUCTION] ?? 0) + ($skills[self::SKILL_ADV_MASS_PRODUCTION] ?? 0);
    }

    protected function calculateResearchSlots(array $skills): int
    {
        return 1 + ($skills[self::SKILL_LABORATORY_OPERATION] ?? 0) + ($skills[self::SKILL_ADV_LABORATORY_OPERATION] ?? 0);
    }

    protected function calculateReactionSlots(array $skills): int
    {
        return 1 + ($skills[self::SKILL_MASS_REACTIONS] ?? 0) + ($skills[self::SKILL_ADV_MASS_REACTIONS] ?? 0);
    }

    protected function getCharacterSkills(Character $character): array
    {
        $skillsData = $this->esi->getCharacterSkills($character);

        if ($skillsData === null || ! isset($skillsData['skills'])) {
            throw new \RuntimeException('Character skills refresh did not complete');
        }

        $skills = [];
        foreach ($skillsData['skills'] as $skill) {
            $skills[$skill['skill_id']] = $skill['active_skill_level'] ?? $skill['trained_skill_level'] ?? 0;
        }

        return $skills;
    }

    protected function getActiveJobs(Character $character): array
    {
        $jobs = $this->esi->getCharacterIndustryJobs($character);

        if ($jobs === null) {
            throw new \RuntimeException('Character industry refresh did not complete');
        }

        // Also fetch corporation jobs started by this character
        $corpJobs = $this->getCorporationJobsForCharacter($character);
        if (! empty($corpJobs)) {
            $jobs = array_merge($jobs, $corpJobs);
        }

        $jobs = array_column($jobs, null, 'job_id');

        return array_filter($jobs, fn ($job) => $job['status'] === 'active');
    }

    /**
     * Jobs whose end date fell in ($since, $until] and that are still awaiting
     * delivery, for the push notification dispatcher. Reads the same cached ESI
     * job lists as the dashboard, so it adds no ESI traffic of its own.
     *
     * @return list<array{job_id: int, character_id: int, character_name: string, activity_id: int, item_name: string, end_date: string, activity_name: string}>
     */
    public function getFinishedJobsForUser(int $userId, CarbonInterface $since, CarbonInterface $until): array
    {
        $activityNames = [
            self::ACTIVITY_MANUFACTURING => 'Manufacturing',
            self::ACTIVITY_RESEARCH_TE => 'Time efficiency research',
            self::ACTIVITY_RESEARCH_ME => 'Material efficiency research',
            self::ACTIVITY_COPYING => 'Copying',
            self::ACTIVITY_INVENTION => 'Invention',
            self::ACTIVITY_REACTIONS => 'Reactions',
        ];
        $finished = [];

        foreach ($this->charactersForUser($userId) as $character) {
            $jobs = $this->esi->getCharacterIndustryJobs($character);
            if ($jobs === null) {
                continue;
            }

            try {
                $jobs = array_merge($jobs, $this->getCorporationJobsForCharacter($character));
            } catch (\RuntimeException) {
                // Corporation jobs are optional here; personal jobs still count.
            }
            $jobs = array_column($jobs, null, 'job_id');

            foreach ($jobs as $job) {
                // ESI reports a finished job as active or ready until it is delivered.
                if (! in_array($job['status'] ?? null, ['active', 'ready'], true) || empty($job['end_date'])) {
                    continue;
                }

                try {
                    $end = Carbon::parse($job['end_date'], 'UTC');
                } catch (\Throwable) {
                    continue;
                }

                if ($end->lte($since) || $end->gt($until)) {
                    continue;
                }

                $typeId = $job['product_type_id'] ?? $job['blueprint_type_id'] ?? null;
                $info = $typeId ? $this->esi->getTypeInfo((int) $typeId) : null;

                $finished[] = [
                    'job_id' => (int) $job['job_id'],
                    'character_id' => (int) $character->character_id,
                    'character_name' => $character->name,
                    'activity_id' => (int) ($job['activity_id'] ?? 0),
                    'item_name' => $info['name'] ?? 'an item',
                    'end_date' => $end->toIso8601String(),
                ];
            }
        }

        return array_map(function (array $job) use ($activityNames) {
            $job['activity_name'] = $activityNames[$job['activity_id']] ?? 'Industry';

            return $job;
        }, $finished);
    }

    /**
     * Fetch corporation industry jobs initiated by the given character.
     */
    protected function getCorporationJobsForCharacter(Character $character, bool $includeCompleted = false): array
    {
        $corporationId = $character->corporation_id;
        if (! $corporationId) {
            return [];
        }

        $collector = RefreshEligibility::corporation(
            $character, $corporationId, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']
        ) ? $character : $this->corporationCollectors($character->user_id, $corporationId)->first();
        if (! $collector) {
            return [];
        }

        $key = $character->user_id.':'.$corporationId.':'.(int) $includeCompleted;
        $corpJobs = $this->corporationJobs[$key]
            ??= $this->esi->getCorporationIndustryJobs($collector, $corporationId, $includeCompleted);
        if ($corpJobs === null) {
            throw new \RuntimeException('Corporation industry refresh did not complete');
        }

        // Only include jobs started by this character
        return array_filter($corpJobs, fn ($job) => ($job['installer_id'] ?? null) === $character->character_id);
    }

    private function corporationCollectors(int $userId, int $corporationId)
    {
        return RefreshEligibility::characters($userId, 'characters')
            ->where('corporation_id', $corporationId)
            ->whereJsonContains('scopes', 'esi-industry.read_corporation_jobs.v1')
            ->whereJsonContains('roles', 'Factory_Manager')
            ->orderByDesc('is_main')->orderBy('character_id')->get()
            ->filter(fn (Character $candidate) => RefreshEligibility::corporation(
                $candidate, $corporationId, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']
            ));
    }

    protected function countJobsByActivity(array $jobs, array $activityIds): int
    {
        return count(array_filter($jobs, fn ($job) => in_array($job['activity_id'], $activityIds)));
    }

    protected function getDetailedJobsByActivity(array $jobs, array $activityIds): array
    {
        $activityNames = [
            self::ACTIVITY_MANUFACTURING => 'Manufacturing',
            self::ACTIVITY_RESEARCH_TE => 'Time Efficiency Research',
            self::ACTIVITY_RESEARCH_ME => 'Material Efficiency Research',
            self::ACTIVITY_COPYING => 'Copying',
            self::ACTIVITY_INVENTION => 'Invention',
            self::ACTIVITY_REACTIONS => 'Reactions',
        ];

        $filtered = array_filter($jobs, fn ($job) => in_array($job['activity_id'], $activityIds));
        $detailed = [];

        foreach ($filtered as $job) {
            $startDate = \Carbon\Carbon::parse($job['start_date']);
            $endDate = \Carbon\Carbon::parse($job['end_date']);
            $now = now();

            $totalDuration = $startDate->diffInSeconds($endDate);
            $elapsed = $startDate->diffInSeconds($now);
            // Remaining seconds until end_date (non-negative)
            $remaining = max(0, $endDate->getTimestamp() - $now->getTimestamp());

            $progressPct = $totalDuration > 0 ? min(100, round(($elapsed / $totalDuration) * 100)) : 100;

            // Format time remaining into days, hours and minutes
            $days = intdiv($remaining, 86400);
            $hours = intdiv($remaining % 86400, 3600);
            $minutes = intdiv(($remaining % 3600), 60);

            $parts = [];
            if ($days > 0) {
                $parts[] = $days.'d';
            }
            if ($hours > 0 || $days > 0) {
                $parts[] = $hours.'h';
            }
            $parts[] = $minutes.'m';

            $timeRemainingStr = implode(' ', $parts);

            // Get the product type ID (what's being produced) or blueprint type ID
            $productTypeId = $job['product_type_id'] ?? $job['blueprint_type_id'] ?? null;
            $blueprintTypeId = $job['blueprint_type_id'] ?? null;

            // Fetch type information for the product/item name
            $itemName = 'Unknown Item';
            $itemImageUrl = null;

            if ($productTypeId) {
                $typeInfo = $this->esi->getTypeInfo($productTypeId);
                if ($typeInfo) {
                    $itemName = $typeInfo['name'] ?? 'Unknown Item';
                } else {
                    \Illuminate\Support\Facades\Log::warning("Missing type info for type {$productTypeId} when building completed jobs list");
                }

                // attempt to find the best image URL (probes multiple styles/sizes)
                try {
                    $best = $this->esi->getBestTypeImageUrl($productTypeId);
                    if ($best) {
                        $itemImageUrl = $best;
                    } else {
                        \Illuminate\Support\Facades\Log::info("No image available for type {$productTypeId}");
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Error probing images for type {$productTypeId}: ".$e->getMessage());
                }
            }

            $probabilityPct = $this->extractProbabilityPct($job);

            $detailed[] = [
                'job_id' => $job['job_id'],
                'activity_id' => $job['activity_id'],
                'activity_name' => $activityNames[$job['activity_id']] ?? 'Unknown',
                'blueprint_type_id' => $blueprintTypeId,
                'product_type_id' => $productTypeId,
                'item_name' => $itemName,
                'item_image_url' => $itemImageUrl,
                'runs' => $job['runs'] ?? 1,
                'licensed_runs' => $job['licensed_runs'] ?? null,
                'start_date' => $startDate->toIso8601String(),
                'end_date' => $endDate->toIso8601String(),
                'progress_pct' => $progressPct,
                'time_remaining' => $timeRemainingStr,
                'seconds_remaining' => $remaining,
                'ready_for_delivery' => $remaining <= 60,
                'status' => $job['status'] ?? 'active',
                'probability_pct' => $probabilityPct,
                'probability_label' => $this->formatProbabilityLabel($probabilityPct),
            ];
        }

        // Sort by end date (soonest first)
        usort($detailed, fn ($a, $b) => $a['seconds_remaining'] <=> $b['seconds_remaining']);

        return $detailed;
    }

    protected function getJobsCompletingSoon(array $jobs): array
    {
        $soon = [];
        $oneHourFromNow = now()->addHour();

        foreach ($jobs as $job) {
            if (isset($job['end_date'])) {
                $endDate = \Carbon\Carbon::parse($job['end_date']);
                if ($endDate->lte($oneHourFromNow) && $endDate->gte(now())) {
                    $soon[] = [
                        'job_id' => $job['job_id'],
                        'activity_id' => $job['activity_id'],
                        'end_date' => $endDate->toIso8601String(),
                        'minutes_remaining' => (int) now()->diffInMinutes($endDate),
                    ];
                }
            }
        }

        return $soon;
    }

    public function clearCache(int $characterId): void
    {
        Cache::forget("industry.slots.{$characterId}");
        if ($character = Character::find($characterId)) {
            Cache::forget($this->slotsCacheKey($character));
        }
    }

    public function clearUserCache(int $userId): void
    {
        $characters = Character::where('user_id', $userId)->get();
        foreach ($characters as $character) {
            $this->clearCache($character->character_id);
        }
        Cache::forget("industry.overview.{$userId}");
        Cache::forget($this->overviewCacheKey($userId));
    }

    public function clearAllCachesForUser(int $userId): void
    {
        $characters = Character::where('user_id', $userId)->get();
        foreach ($characters as $character) {
            // clear industry slot cache
            $this->clearCache($character->character_id);
            // clear ESI caches (skills, jobs, character)
            try {
                $this->esi->clearCharacterCache($character->character_id);
            } catch (\Throwable $e) {
                // swallow; clearing cache shouldn't break
            }
            // clear corporation industry jobs cache
            try {
                if ($character->corporation_id) {
                    $this->esi->clearCorporationIndustryJobsCache($character->corporation_id);
                }
            } catch (\Throwable $e) {
                // swallow; clearing cache shouldn't break
            }
        }
        Cache::forget("industry.overview.{$userId}");
        Cache::forget($this->overviewCacheKey($userId));
    }

    public function getRecentCompletedJobsForUser(int $userId, int $hours = 24): array
    {
        $characters = $this->charactersForUser($userId);
        $recent = [];
        $cutoff = now()->subHours($hours);

        foreach ($characters as $character) {
            // include only explicit completed jobs from ESI (don't add active near-complete jobs)
            $completed = $this->esi->getCharacterIndustryJobs($character, true) ?: [];

            // Also include completed corporation jobs started by this character
            $corpCompleted = $this->getCorporationJobsForCharacter($character, true);
            if (! empty($corpCompleted)) {
                $completed = array_merge($completed, $corpCompleted);
            }

            foreach ($completed as $job) {
                if (($job['status'] ?? '') !== 'completed') {
                    continue;
                }
                if (empty($job['end_date'])) {
                    continue;
                }

                $endDate = \Carbon\Carbon::parse($job['end_date']);
                if ($endDate->lt($cutoff)) {
                    continue;
                }

                // determine location name
                $locationName = 'Unknown Location';
                if (! empty($job['station_id'])) {
                    $station = $this->esi->getStation($job['station_id']);
                    if ($station && isset($station['name'])) {
                        $locationName = $station['name'];
                    }
                } elseif (! empty($job['solar_system_id'])) {
                    $system = $this->esi->getSystem($job['solar_system_id']);
                    if ($system && isset($system['name'])) {
                        $locationName = $system['name'];
                    }
                }

                $productTypeId = $job['product_type_id'] ?? $job['blueprint_type_id'] ?? null;
                $itemName = 'Unknown Item';
                $itemImage = null;
                if ($productTypeId) {
                    $typeInfo = $this->esi->getTypeInfo($productTypeId);
                    if ($typeInfo) {
                        $itemName = $typeInfo['name'] ?? $itemName;
                    }
                    try {
                        $best = $this->esi->getBestTypeImageUrl($productTypeId);
                        if ($best) {
                            $itemImage = $best;
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Error probing images for type {$productTypeId} (recent): ".$e->getMessage());
                    }
                }

                $activityNames = [
                    self::ACTIVITY_MANUFACTURING => 'Manufacturing',
                    self::ACTIVITY_RESEARCH_TE => 'Research (TE)',
                    self::ACTIVITY_RESEARCH_ME => 'Research (ME)',
                    self::ACTIVITY_COPYING => 'Copying',
                    self::ACTIVITY_INVENTION => 'Invention',
                    self::ACTIVITY_REACTIONS => 'Reactions',
                ];

                $probabilityPct = $this->extractProbabilityPct($job);

                $recent[] = [
                    'character_id' => $character->character_id,
                    'character_name' => $character->name,
                    'job_id' => $job['job_id'],
                    'activity_id' => $job['activity_id'],
                    'activity_name' => $activityNames[$job['activity_id']] ?? 'Unknown',
                    'item_name' => $itemName,
                    'item_image_url' => $itemImage,
                    'end_date' => $endDate->toIso8601String(),
                    'location' => $locationName,
                    'probability_pct' => $probabilityPct,
                    'probability_label' => $this->formatProbabilityLabel($probabilityPct),
                ];
            }
        }

        // sort by newest first
        usort($recent, fn ($a, $b) => strtotime($b['end_date']) <=> strtotime($a['end_date']));

        return $recent;
    }

    public function getReadyForCollectionJobsForUser(int $userId, int $secondsWindow = 60): array
    {
        $characters = $this->charactersForUser($userId);
        $ready = [];
        // Computed once: addSeconds() on a mutable Carbon inside the loop would widen the window per job.
        $cutoff = now()->addSeconds($secondsWindow);

        foreach ($characters as $character) {
            // fetch active jobs for the character
            $activeJobs = $this->getActiveJobs($character) ?: [];
            if (! empty($activeJobs)) {
                foreach ($activeJobs as $job) {

                    if (empty($job['end_date'])) {
                        continue;
                    }

                    try {
                        $end = \Carbon\Carbon::parse($job['end_date'], 'UTC');
                    } catch (\Throwable $e) {
                        continue;
                    }

                    // must be in the future but within the window
                    if ($end->lte($cutoff)) {
                        // resolve location
                        $locationName = 'Unknown Location';
                        if (! empty($job['station_id'])) {
                            $station = $this->esi->getStation($job['station_id']);
                            if ($station && isset($station['name'])) {
                                $locationName = $station['name'];
                            }
                        } elseif (! empty($job['solar_system_id'])) {
                            $system = $this->esi->getSystem($job['solar_system_id']);
                            if ($system && isset($system['name'])) {
                                $locationName = $system['name'];
                            }
                        }

                        $productTypeId = $job['product_type_id'] ?? $job['blueprint_type_id'] ?? null;
                        $itemName = 'Unknown Item';
                        $itemImage = null;
                        if ($productTypeId) {
                            $typeInfo = $this->esi->getTypeInfo($productTypeId);
                            if ($typeInfo) {
                                $itemName = $typeInfo['name'] ?? $itemName;
                            }
                            try {
                                $best = $this->esi->getBestTypeImageUrl($productTypeId);
                                if ($best) {
                                    $itemImage = $best;
                                }
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning("Error probing images for type {$productTypeId} (ready): ".$e->getMessage());
                            }
                        }

                        $activityNames = [
                            self::ACTIVITY_MANUFACTURING => 'Manufacturing',
                            self::ACTIVITY_RESEARCH_TE => 'Research (TE)',
                            self::ACTIVITY_RESEARCH_ME => 'Research (ME)',
                            self::ACTIVITY_COPYING => 'Copying',
                            self::ACTIVITY_INVENTION => 'Invention',
                            self::ACTIVITY_REACTIONS => 'Reactions',
                        ];

                        $probabilityPct = $this->extractProbabilityPct($job);

                        $ready[] = [
                            'character_id' => $character->character_id,
                            'character_name' => $character->name,
                            'job_id' => $job['job_id'],
                            'activity_id' => $job['activity_id'] ?? null,
                            'activity_name' => $activityNames[$job['activity_id']] ?? 'Unknown',
                            'item_name' => $itemName,
                            'item_image_url' => $itemImage,
                            'end_date' => $end->toIso8601String(),
                            'location' => $locationName,
                            'runs' => $job['runs'] ?? 1,
                            'probability_pct' => $probabilityPct,
                            'probability_label' => $this->formatProbabilityLabel($probabilityPct).' Chance',
                        ];
                    }
                }
            }
        }

        // sort by soonest first
        usort($ready, fn ($a, $b) => strtotime($a['end_date']) <=> strtotime($b['end_date']));

        return $ready;
    }

    // Helper to extract the probability percentage (0-100) from job data
    protected function extractProbabilityPct(array $job): ?int
    {
        if ($job['activity_id'] == self::ACTIVITY_INVENTION && isset($job['probability'])) {
            return round($job['probability'] * 100, 2);
        }

        return null;
    }

    protected function resolveJobLocation(array $job, Character $character): string
    {
        $locationName = 'Unknown Location';

        $stationId = $job['station_id'] ?? null;
        if ($stationId) {
            $station = $this->esi->getStation($stationId);
            if ($station && isset($station['name'])) {
                return $station['name'];
            }

            // station_id may refer to a player-owned structure
            $structure = $this->esi->getStructure($stationId, $character);
            if ($structure && isset($structure['name'])) {
                return $structure['name'];
            }
        }

        if (! empty($job['facility_id']) && $job['facility_id'] !== $stationId) {
            $structure = $this->esi->getStructure($job['facility_id'], $character);
            if ($structure && isset($structure['name'])) {
                return $structure['name'];
            }
        }

        if (! empty($job['solar_system_id'])) {
            $system = $this->esi->getSystem($job['solar_system_id']);
            if ($system && isset($system['name'])) {
                return $system['name'];
            }
        }

        return $locationName;
    }

    protected function formatProbabilityLabel(?int $pct): ?string
    {
        if ($pct === null || $pct <= 0) {
            return null;
        }

        return $pct.'%';
    }

    public function getJobsOverviewForUser(int $userId): array
    {
        $cacheKey = $this->overviewCacheKey($userId);

        return Cache::remember($cacheKey, self::INDUSTRY_CACHE_TTL, function () use ($userId) {
            return $this->buildJobsOverview($userId);
        });
    }

    protected function buildJobsOverview(int $userId): array
    {
        $characters = $this->charactersForUser($userId);
        $activityNames = [
            self::ACTIVITY_MANUFACTURING => 'Manufacturing',
            self::ACTIVITY_RESEARCH_TE => 'Time Efficiency Research',
            self::ACTIVITY_RESEARCH_ME => 'Material Efficiency Research',
            self::ACTIVITY_COPYING => 'Copying',
            self::ACTIVITY_INVENTION => 'Invention',
            self::ACTIVITY_REACTIONS => 'Reactions',
        ];

        $groupedJobs = [];

        foreach ($characters as $character) {
            $jobs = $this->getActiveJobs($character);

            foreach ($jobs as $job) {
                $activityId = $job['activity_id'];
                $activityName = $activityNames[$activityId] ?? 'Unknown';

                // Skip if activity not in our known list
                if (! isset($activityNames[$activityId])) {
                    continue;
                }

                $startDate = \Carbon\Carbon::parse($job['start_date']);
                $endDate = \Carbon\Carbon::parse($job['end_date']);
                $now = now();

                $totalDuration = $startDate->diffInSeconds($endDate);
                $elapsed = $startDate->diffInSeconds($now);
                $remaining = max(0, $endDate->getTimestamp() - $now->getTimestamp());

                $progressPct = $totalDuration > 0 ? min(100, round(($elapsed / $totalDuration) * 100)) : 100;

                // Format time remaining
                $days = intdiv($remaining, 86400);
                $hours = intdiv($remaining % 86400, 3600);
                $minutes = intdiv(($remaining % 3600), 60);

                $parts = [];
                if ($days > 0) {
                    $parts[] = $days.'d';
                }
                if ($hours > 0 || $days > 0) {
                    $parts[] = $hours.'h';
                }
                $parts[] = $minutes.'m';

                $timeRemainingStr = implode(' ', $parts);

                // Get item info
                $productTypeId = $job['product_type_id'] ?? $job['blueprint_type_id'] ?? null;
                $itemName = 'Unknown Item';
                $itemImageUrl = null;

                if ($productTypeId) {
                    $typeInfo = $this->esi->getTypeInfo($productTypeId);
                    if ($typeInfo) {
                        $itemName = $typeInfo['name'] ?? 'Unknown Item';
                    }

                    try {
                        $best = $this->esi->getBestTypeImageUrl($productTypeId);
                        if ($best) {
                            $itemImageUrl = $best;
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Error probing images for type {$productTypeId}: ".$e->getMessage());
                    }
                }

                $probabilityPct = $this->extractProbabilityPct($job);
                $runs = $job['runs'] ?? 1;

                // Calculate expected output
                if ($activityId == self::ACTIVITY_INVENTION && isset($job['probability'])) {
                    $expectedOutput = round($runs * $job['probability'], 2);
                } elseif (in_array($activityId, [self::ACTIVITY_MANUFACTURING, self::ACTIVITY_REACTIONS])) {
                    $outputPerRun = 1;
                    $sdeResolved = false;
                    $blueprintTypeId = $job['blueprint_type_id'] ?? null;
                    if ($blueprintTypeId) {
                        $sdeActivityId = $activityId === self::ACTIVITY_REACTIONS ? 11 : $activityId;
                        $bpData = $this->esi->resolveBlueprintActivity($blueprintTypeId, $sdeActivityId);
                        if ($bpData && ! empty($bpData['products'])) {
                            $matched = null;
                            if ($productTypeId) {
                                foreach ($bpData['products'] as $product) {
                                    if (($product['type_id'] ?? null) == $productTypeId) {
                                        $matched = $product;
                                        break;
                                    }
                                }
                            }
                            $outputPerRun = (int) (($matched ?? $bpData['products'][0])['quantity'] ?? 1);
                            $sdeResolved = true;
                        }
                    }
                    // Fallback: use portion_size from ESI type info when SDE data is unavailable
                    if (! $sdeResolved && isset($typeInfo['portion_size']) && $typeInfo['portion_size'] > 1) {
                        $outputPerRun = (int) $typeInfo['portion_size'];
                    }
                    $expectedOutput = $runs * $outputPerRun;
                } else {
                    $expectedOutput = $runs;
                }

                // Resolve location name
                $locationName = $this->resolveJobLocation($job, $character);

                $jobRecord = [
                    'job_id' => $job['job_id'],
                    'activity_id' => $activityId,
                    'activity_name' => $activityName,
                    'character_name' => $character->name,
                    'type_id' => $productTypeId,
                    'item_name' => $itemName,
                    'item_image_url' => $itemImageUrl,
                    'runs' => $runs,
                    'probability_pct' => $probabilityPct,
                    'expected_output' => $expectedOutput,
                    'time_remaining' => $timeRemainingStr,
                    'seconds_remaining' => $remaining,
                    'end_date' => $endDate->toIso8601String(),
                    'progress_pct' => $progressPct,
                    'ready_for_delivery' => $remaining <= 60,
                    'location' => $locationName,
                ];

                // Add to appropriate group
                if (! isset($groupedJobs[$activityName])) {
                    $groupedJobs[$activityName] = [];
                }
                $groupedJobs[$activityName][] = $jobRecord;
            }
        }

        // Sort each group by seconds_remaining ascending
        foreach ($groupedJobs as $activityName => $jobs) {
            usort($groupedJobs[$activityName], fn ($a, $b) => $a['seconds_remaining'] <=> $b['seconds_remaining']);
        }

        return $groupedJobs;
    }

    public function buildProductionSummary(array $groupedJobs): array
    {
        $summary = [];
        $valueActivities = ['Manufacturing', 'Reactions'];

        foreach ($groupedJobs as $activityName => $jobs) {
            if (! in_array($activityName, $valueActivities)) {
                continue;
            }
            foreach ($jobs as $job) {
                $key = $job['item_name'];

                if (! isset($summary[$key])) {
                    $summary[$key] = [
                        'type_id' => $job['type_id'] ?? null,
                        'item_name' => $job['item_name'],
                        'item_image_url' => $job['item_image_url'],
                        'total_expected_output' => 0,
                        'total_runs' => 0,
                        'job_count' => 0,
                    ];
                }

                $summary[$key]['total_expected_output'] += $job['expected_output'];
                $summary[$key]['total_runs'] += $job['runs'];
                $summary[$key]['job_count']++;
            }
        }

        $result = array_values(array_map(function ($item) {
            $item['total_expected_output'] = (int) floor($item['total_expected_output']);

            return $item;
        }, $summary));
        usort($result, fn ($a, $b) => $a['item_name'] <=> $b['item_name']);

        return $result;
    }
}
