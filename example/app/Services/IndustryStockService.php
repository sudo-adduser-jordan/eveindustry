<?php

namespace App\Services;

use App\Models\EveType;
use App\Models\IndustryStockItem;
use App\Models\User;

class IndustryStockService
{
    /** Maximum typeahead results returned by searchTypes(). */
    public const SEARCH_LIMIT = 20;

    public function __construct(protected AssetService $assetService) {}

    /**
     * Build the per-user stock report.
     *
     * Scopes everything to the given user — corporate asset data comes from
     * the user's own main character (which must be a director/CEO with the
     * corporate assets scope). Other users, including corp members, are never
     * involved in the calculation.
     *
     * @return array{items: array<int, array<string, mixed>>, warning: ?string}
     */
    public function getStockReport(int $userId): array
    {
        $warning = $this->assetService->getCorporationAssetsWarning($userId);

        $onHandByTypeId = [];
        if ($warning === null) {
            $assets = $this->assetService->getAssetsForUser($userId);
            foreach ($assets as $asset) {
                if (($asset['owner_type'] ?? null) !== 'corporate') {
                    continue;
                }
                $typeId = (int) ($asset['type_id'] ?? 0);
                if ($typeId <= 0) {
                    continue;
                }
                $onHandByTypeId[$typeId] = ($onHandByTypeId[$typeId] ?? 0) + (int) ($asset['quantity'] ?? 0);
            }
        }

        $items = IndustryStockItem::where('user_id', $userId)
            ->orderBy('type_name')
            ->get()
            ->map(function (IndustryStockItem $item) use ($onHandByTypeId) {
                $desired = (int) $item->desired_quantity;
                $onHand = (int) ($onHandByTypeId[$item->type_id] ?? 0);
                $shortfall = max(0, $desired - $onHand);

                return [
                    'id' => $item->id,
                    'type_id' => $item->type_id,
                    'type_name' => $item->type_name,
                    'desired' => $desired,
                    'on_hand' => $onHand,
                    'shortfall' => $shortfall,
                    'icon_url' => EsiService::getTypeImageUrl($item->type_id, 'icon', 32),
                ];
            })
            ->values()
            ->toArray();

        return [
            'items' => $items,
            'warning' => $warning,
        ];
    }

    /**
     * Typeahead search over the local EveType catalog, published types only.
     *
     * @return array<int, array{type_id:int, name:string, icon_url:string}>
     */
    public function searchTypes(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $escaped = addcslashes($query, '%_');

        return EveType::where('name', 'like', '%'.$escaped.'%')
            ->where('published', true)
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (EveType $type) => [
                'type_id' => (int) $type->type_id,
                'name' => $type->name,
                'icon_url' => EsiService::getTypeImageUrl($type->type_id, 'icon', 32),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Convenience for explicit per-user scoping; mirrors the User relation.
     */
    public function itemsForUser(User $user)
    {
        return $user->industryStockItems()->orderBy('type_name');
    }

    /**
     * Set the desired stock of a type for a user: creates the item, or
     * updates the quantity if the user already tracks that type. The type
     * name is copied from eve_types.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the type does not exist
     */
    public function saveItem(int $userId, int $typeId, int $desiredQuantity): IndustryStockItem
    {
        $type = EveType::where('type_id', $typeId)->firstOrFail();

        return IndustryStockItem::updateOrCreate(
            [
                'user_id' => $userId,
                'type_id' => $typeId,
            ],
            [
                'type_name' => $type->name,
                'desired_quantity' => $desiredQuantity,
            ]
        );
    }

    public function updateItemQuantity(IndustryStockItem $item, int $desiredQuantity): IndustryStockItem
    {
        $item->update(['desired_quantity' => $desiredQuantity]);

        return $item;
    }

    public function deleteItem(IndustryStockItem $item): void
    {
        $item->delete();
    }

    /**
     * The item as the old stock manager routes return it.
     *
     * @return array{id: int, type_id: int, type_name: string, desired_quantity: int}
     */
    public function itemPayload(IndustryStockItem $item): array
    {
        return [
            'id' => $item->id,
            'type_id' => $item->type_id,
            'type_name' => $item->type_name,
            'desired_quantity' => (int) $item->desired_quantity,
        ];
    }
}
