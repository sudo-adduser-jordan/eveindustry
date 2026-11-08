<?php

namespace App\Services\Heavy;

use App\Services\ReprocessingService;

/**
 * The reprocessing analysis with its totals. Shared by the old
 * GET /api/industry/reprocessing and GET /api/v1/industry/reprocessing.
 */
class ReprocessingSummaryService
{
    public function __construct(private ReprocessingService $reprocessing) {}

    /**
     * @return array{characters: array<int, array<string, mixed>>, totalProfit: int|float, totalCharacters: int, totalItems: int}
     */
    public function forUser(int $userId): array
    {
        $characters = $this->reprocessing->getReprocessingOpportunities($userId);

        $totalItems = 0;
        foreach ($characters as $char) {
            foreach ($char['locations'] as $loc) {
                $totalItems += count($loc['items']);
            }
        }

        return [
            'characters' => $characters,
            'totalProfit' => array_sum(array_column($characters, 'total_profit')),
            'totalCharacters' => count($characters),
            'totalItems' => $totalItems,
        ];
    }
}
