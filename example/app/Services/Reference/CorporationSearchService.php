<?php

namespace App\Services\Reference;

use App\Models\Character;
use App\Services\EsiService;

/**
 * Corporation name search through the authenticated ESI character search,
 * using the user's main character (or any of their characters). Shared by
 * /api/tools/moon-scan/corporation-search and /api/v1/corporations.
 */
class CorporationSearchService
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    public const LIMIT = 10;

    public function __construct(private EsiService $esi) {}

    /**
     * Up to 10 corporations matching $query. Empty when the user has no
     * character or ESI returns nothing; corporations whose details ESI cannot
     * load are skipped.
     *
     * @return list<array{id: int, name: string, ticker: string}>
     */
    public function searchForUser(?int $userId, string $query): array
    {
        $character = $this->characterFor($userId);
        if (! $character) {
            return [];
        }

        $searchResult = $this->esi->searchCharacter($character, $query, 'corporation');
        if (! $searchResult || empty($searchResult['corporation'])) {
            return [];
        }

        $corporations = [];
        foreach (array_slice($searchResult['corporation'], 0, self::LIMIT) as $corpId) {
            $corpInfo = $this->esi->getCorporation((int) $corpId);
            if ($corpInfo) {
                $corporations[] = [
                    'id' => $corpId,
                    'name' => $corpInfo['name'] ?? 'Unknown',
                    'ticker' => $corpInfo['ticker'] ?? '',
                ];
            }
        }

        return $corporations;
    }

    private function characterFor(?int $userId): ?Character
    {
        return Character::where('user_id', $userId)->where('is_main', true)->first()
            ?? Character::where('user_id', $userId)->first();
    }
}
