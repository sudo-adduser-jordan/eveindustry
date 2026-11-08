<?php

namespace App\Services\Admin;

use App\Services\IndustryService;
use App\Services\MarketService;
use App\Services\MarketTradingService;

/**
 * A user's active industry jobs grouped by activity, priced at one hub's
 * sell prices (the admin view of another user's jobs).
 */
class UserJobsOverviewService
{
    private const VALUE_ACTIVITIES = ['Manufacturing', 'Reactions'];

    public function __construct(
        private readonly IndustryService $industry,
        private readonly MarketService $market,
    ) {}

    public function regionOrDefault(int $regionId): int
    {
        return array_key_exists($regionId, MarketTradingService::REGIONS) ? $regionId : MarketService::DEFAULT_REGION_ID;
    }

    /**
     * @return array{region_id: int, groups: list<array<string, mixed>>, summary: list<array<string, mixed>>, grand_total: float|null}
     */
    public function forUser(int $userId, int $regionId): array
    {
        $regionId = $this->regionOrDefault($regionId);
        $grouped = $this->industry->getJobsOverviewForUser($userId);
        $summary = $this->industry->buildProductionSummary($grouped);

        $typeIds = [];
        foreach ($grouped as $jobs) {
            foreach ($jobs as $job) {
                if ($job['type_id'] !== null) {
                    $typeIds[] = $job['type_id'];
                }
            }
        }
        $prices = $this->market->getSellPrices(array_values(array_unique($typeIds)), $regionId);

        $value = fn (?int $typeId, float|int $quantity): ?float => ($typeId && ($prices[$typeId] ?? 0) > 0)
            ? (float) ($prices[$typeId] * $quantity)
            : null;

        $groups = [];
        foreach ($grouped as $activity => $jobs) {
            $priced = in_array($activity, self::VALUE_ACTIVITIES, true);
            $groups[] = [
                'activity_name' => (string) $activity,
                'jobs' => array_map(fn (array $job) => $job + [
                    'estimated_value' => $priced ? $value($job['type_id'], $job['expected_output']) : null,
                ], $jobs),
            ];
        }

        $grandTotal = 0.0;
        $summary = array_map(function (array $item) use ($value, &$grandTotal) {
            $item['estimated_value'] = $value($item['type_id'], $item['total_expected_output']);
            $grandTotal += $item['estimated_value'] ?? 0;

            return $item;
        }, $summary);

        return [
            'region_id' => $regionId,
            'groups' => $groups,
            'summary' => $summary,
            'grand_total' => $grandTotal > 0 ? $grandTotal : null,
        ];
    }
}
