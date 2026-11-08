<?php

namespace App\Services;

/**
 * The industry jobs overview with ISK values: active jobs grouped by
 * activity, and the manufacturing and reaction output totalled per item.
 */
class JobsOverviewService
{
    /** Activities whose jobs produce items that can be priced. */
    private const VALUE_ACTIVITIES = ['Manufacturing', 'Reactions'];

    public function __construct(
        private IndustryService $industry,
        private MarketService $market,
    ) {}

    /**
     * @return array{groups: list<array{activity_name: string, jobs: list<array<string, mixed>>}>, summary: list<array<string, mixed>>, estimated_total: ?float, total_jobs: int}
     */
    public function forUser(int $userId, int $regionId): array
    {
        $grouped = $this->industry->getJobsOverviewForUser($userId);
        $summary = $this->industry->buildProductionSummary($grouped);

        $typeIds = [];
        foreach ($grouped as $jobs) {
            foreach ($jobs as $job) {
                if ($job['type_id'] !== null) {
                    $typeIds[] = (int) $job['type_id'];
                }
            }
        }
        $prices = $this->market->getSellPrices(array_values(array_unique($typeIds)), $regionId);

        $value = fn (?int $typeId, float|int $quantity): ?float => ($typeId !== null && ($prices[$typeId] ?? 0) > 0)
            ? (float) $prices[$typeId] * $quantity
            : null;

        $groups = [];
        $totalJobs = 0;
        foreach ($grouped as $activityName => $jobs) {
            $priced = in_array($activityName, self::VALUE_ACTIVITIES, true);
            $rows = [];
            foreach ($jobs as $job) {
                $typeId = $job['type_id'] !== null ? (int) $job['type_id'] : null;
                $rows[] = $job + [
                    'estimated_value' => $priced ? $value($typeId, $job['expected_output']) : null,
                ];
            }
            $totalJobs += count($rows);
            $groups[] = ['activity_name' => (string) $activityName, 'jobs' => $rows];
        }

        $total = 0.0;
        $items = [];
        foreach ($summary as $item) {
            $itemValue = $value($item['type_id'] !== null ? (int) $item['type_id'] : null, $item['total_expected_output']);
            $total += $itemValue ?? 0;
            $items[] = $item + ['estimated_value' => $itemValue];
        }

        return [
            'groups' => $groups,
            'summary' => $items,
            'estimated_total' => $total > 0 ? $total : null,
            'total_jobs' => $totalJobs,
        ];
    }
}
