<?php

namespace App\Services;

use App\Models\Character;

class CorporationProjectsService
{
    public function __construct(protected EsiService $esi) {}

    /**
     * Return the main character belonging to the user if it has the corporation projects scope.
     *
     * @return \Illuminate\Support\Collection<int, Character>
     */
    public function getUserProjectsCharacters(int $userId): \Illuminate\Support\Collection
    {
        return Character::where('user_id', $userId)
            ->where('is_main', true)
            ->get()
            ->filter(function (Character $character) {
                return in_array('esi-corporations.read_projects.v1', $character->scopes ?? [])
                    && $character->corporation_id;
            })
            ->values();
    }

    /**
     * Get all projects for a user's corporation with per-project metrics.
     */
    public function getProjectsForUser(int $userId): array
    {
        $character = $this->getUserProjectsCharacters($userId)->first();

        if (! $character) {
            return $this->emptyResult();
        }

        $projects = $this->esi->getCorporationProjects($character, $character->corporation_id);

        return $this->buildAnalytics($projects, $character);
    }

    /**
     * Clear the ESI cache for the user's corporation projects and their contributors.
     */
    public function clearCache(int $userId): void
    {
        $character = $this->getUserProjectsCharacters($userId)->first();

        if (! $character) {
            return;
        }

        // Clear the projects list cache; also clear any already-cached contributor entries
        $cached = \Illuminate\Support\Facades\Cache::get("esi.corp_projects.{$character->corporation_id}", []);
        foreach ($cached as $project) {
            if (isset($project['id'])) {
                $this->esi->clearCorporationProjectContributorsCache($character->corporation_id, $project['id']);
            }
        }

        $this->esi->clearCorporationProjectsCache($character->corporation_id);
    }

    /**
     * Compute per-project metrics, fetch contributors, and aggregate totals.
     */
    public function buildAnalytics(array $projects, Character $character): array
    {
        $completed = 0;
        $inProgress = 0;
        $totalProgress = 0;
        $totalDesired = 0;
        $totalRewardInitial = 0;
        $totalRewardRemaining = 0;
        $totalRewardEarned = 0;

        foreach ($projects as &$project) {
            $current = $project['progress']['current'] ?? 0;
            $desired = $project['progress']['desired'] ?? 0;

            $project['completion_pct'] = $desired > 0 ? min(100, round($current / $desired * 100, 1)) : 0;
            $project['is_complete'] = ($desired > 0 && $current >= $desired);

            if ($project['is_complete']) {
                $completed++;
            } elseif ($current > 0) {
                $inProgress++;
            }

            $totalProgress += $current;
            $totalDesired += $desired;

            if (isset($project['reward'])) {
                $initial = $project['reward']['initial'] ?? 0;
                $remaining = $project['reward']['remaining'] ?? 0;
                $project['reward_earned'] = $initial - $remaining;
                $totalRewardInitial += $initial;
                $totalRewardRemaining += $remaining;
                $totalRewardEarned += $project['reward_earned'];
            } else {
                $project['reward_earned'] = 0;
            }

            // Fetch contributors for this project
            $project['contributors'] = $this->esi->getCorporationProjectContributors(
                $character,
                $character->corporation_id,
                $project['id']
            );

        }
        // Must unset the reference before the read-only loop below; otherwise the
        // by-value loop variable would overwrite the last element via the reference.
        unset($project);

        // Use the contributor name returned by the API directly
        foreach ($projects as &$project) {
            foreach ($project['contributors'] as &$contributor) {
                $contributor['character_name'] = $contributor['name'] ?? null;
            }
            unset($contributor);
        }
        unset($project);

        usort($projects, function ($a, $b) {
            return strtotime($b['last_modified'] ?? '0') <=> strtotime($a['last_modified'] ?? '0');
        });

        return [
            'projects' => $projects,
            'corporation_name' => $character->corporation_name,
            'corporation_id' => $character->corporation_id,
            'total_projects' => count($projects),
            'completed_projects' => $completed,
            'in_progress_projects' => $inProgress,
            'total_progress' => $totalProgress,
            'total_desired' => $totalDesired,
            'overall_completion_pct' => $totalDesired > 0 ? min(100, round($totalProgress / $totalDesired * 100, 1)) : 0,
            'total_reward_initial' => $totalRewardInitial,
            'total_reward_remaining' => $totalRewardRemaining,
            'total_reward_earned' => $totalRewardEarned,
        ];
    }

    /**
     * Build a contributor leaderboard for rewarded projects over an optional date range.
     *
     * Only projects that carry a `reward` field are included.
     * ISK attribution per contributor = quantity × (reward.initial / progress.desired).
     * Results are sorted descending by attributed ISK.
     */
    public function buildContributorLeaderboard(
        int $userId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $projectName = null
    ): array {
        $character = $this->getUserProjectsCharacters($userId)->first();

        if (! $character) {
            return $this->emptyLeaderboard($dateFrom, $dateTo, $projectName);
        }

        $projects = $this->esi->getCorporationProjects($character, $character->corporation_id);

        // Keep only projects with a reward value
        $rewarded = array_filter($projects, fn ($p) => isset($p['reward']['initial']) && $p['reward']['initial'] > 0 && in_array($p['state'], ['Closed', 'Completed']));

        // Apply optional project name filter (case-insensitive partial match)
        if ($projectName) {
            $rewarded = array_filter($rewarded, fn (array $project) => stripos($project['name'] ?? '', $projectName) !== false);
        }

        // Apply optional date range filter on last_modified
        if ($dateFrom || $dateTo) {
            $from = $dateFrom ? \Carbon\Carbon::parse($dateFrom)->startOfDay() : null;
            $to = $dateTo ? \Carbon\Carbon::parse($dateTo)->endOfDay() : null;

            $rewarded = array_filter($rewarded, function (array $project) use ($from, $to) {
                $modified = \Carbon\Carbon::parse($project['last_modified']);

                return (! $from || $modified->gte($from)) && (! $to || $modified->lte($to));
            });
        }

        $rewarded = array_values($rewarded);

        // Aggregate per contributor
        $byContributor = [];   // keyed by character_id
        $totalAttributed = 0.0;

        foreach ($rewarded as $project) {

            $desired = (float) ($project['progress']['desired'] ?? 0);
            $rewardIsk = (float) ($project['reward']['initial'] ?? 0);
            $iskPerUnit = $desired > 0 ? $rewardIsk / $desired : 0.0;

            $contributors = $this->esi->getCorporationProjectContributors(
                $character,
                $character->corporation_id,
                $project['id']
            );

            foreach ($contributors as $contrib) {
                $cid = $contrib['character_id'] ?? null;
                $cname = $contrib['name'] ?? $contrib['character_name'] ?? null;

                $quantity = (float) ($contrib['contributed'] ?? $contrib['amount'] ?? 0);
                $isk = round($quantity * $iskPerUnit, 2);

                $key = $cid ?? ('anon_'.($cname ?? 'unknown'));

                if (! isset($byContributor[$key])) {
                    $byContributor[$key] = [
                        'character_id' => $cid,
                        'character_name' => $cname,
                        'project_count' => 0,
                        'attributed_isk' => 0.0,
                        'projects' => [],
                    ];
                }

                $byContributor[$key]['attributed_isk'] += $isk;
                $byContributor[$key]['project_count']++;
                $byContributor[$key]['projects'][] = [
                    'id' => $project['id'],
                    'name' => $project['name'],
                    'quantity' => (int) $quantity,
                    'attributed_isk' => $isk,
                ];

                $totalAttributed += $isk;
            }
        }

        // Compute percentage share and sort descending
        foreach ($byContributor as &$entry) {
            $entry['attributed_pct'] = $totalAttributed > 0
                ? round($entry['attributed_isk'] / $totalAttributed * 100, 2)
                : 0.0;
        }
        unset($entry);

        usort($byContributor, fn ($a, $b) => $b['attributed_isk'] <=> $a['attributed_isk']);

        return [
            'corporation_name' => $character->corporation_name,
            'contributors' => array_values($byContributor),
            'total_attributed_isk' => $totalAttributed,
            'total_rewarded_projects' => count($rewarded),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'project_name' => $projectName,
        ];
    }

    public function emptyLeaderboard(?string $dateFrom = null, ?string $dateTo = null, ?string $projectName = null): array
    {
        return [
            'corporation_name' => null,
            'contributors' => [],
            'total_attributed_isk' => 0.0,
            'total_rewarded_projects' => 0,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'project_name' => $projectName,
        ];
    }

    public function emptyResult(): array
    {
        return [
            'projects' => [],
            'corporation_name' => null,
            'corporation_id' => null,
            'total_projects' => 0,
            'completed_projects' => 0,
            'in_progress_projects' => 0,
            'total_progress' => 0,
            'total_desired' => 0,
            'overall_completion_pct' => 0,
            'total_reward_initial' => 0,
            'total_reward_remaining' => 0,
            'total_reward_earned' => 0,
        ];
    }
}
