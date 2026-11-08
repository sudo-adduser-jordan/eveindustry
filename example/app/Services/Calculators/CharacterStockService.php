<?php

namespace App\Services\Calculators;

use App\Models\User;
use App\Services\EsiService;
use App\Services\ReactionFormulaResearchService;

/**
 * What a user already owns, for the calculators' stock checks: asset
 * quantities across their characters and owned reaction formulas.
 */
class CharacterStockService
{
    public const ASSETS_SCOPE = 'esi-assets.read_assets.v1';

    public function __construct(
        protected EsiService $esi,
        protected ReactionFormulaResearchService $formulas,
    ) {}

    /**
     * Total asset quantity per requested type id, summed over the user's
     * characters that have the assets scope. Characters whose ESI call
     * fails are skipped; types with no stock are left out.
     *
     * @param  array<int, int|string>  $typeIds
     * @return array<int, int>
     */
    public function assetStock(User $user, array $typeIds): array
    {
        $characters = $user->characters ?? collect();
        $stock = [];

        foreach ($characters as $character) {
            $scopes = $character->scopes ?? [];
            if (! in_array(self::ASSETS_SCOPE, $scopes)) {
                continue;
            }

            try {
                $assets = $this->esi->getCharacterAssets($character);
            } catch (\Exception $e) {
                continue;
            }

            foreach ($assets as $asset) {
                $typeId = $asset['type_id'] ?? 0;
                $quantity = $asset['quantity'] ?? 0;
                if ($typeId && $quantity > 0 && in_array($typeId, $typeIds)) {
                    $stock[$typeId] = ($stock[$typeId] ?? 0) + $quantity;
                }
            }
        }

        return $stock;
    }

    /**
     * One row per requested type id (duplicates dropped), with 0 for types
     * missing from $stock.
     *
     * @param  array<int, int|string>  $typeIds
     * @param  array<int, int>  $stock  type_id => quantity
     * @return array<int, array{type_id: int, quantity: int}>
     */
    public function levels(array $typeIds, array $stock): array
    {
        $rows = [];
        foreach (array_unique(array_map('intval', $typeIds)) as $typeId) {
            $rows[] = ['type_id' => $typeId, 'quantity' => (int) ($stock[$typeId] ?? 0)];
        }

        return $rows;
    }

    /**
     * Owned count per requested reaction formula (0 when not owned). The
     * stock is empty when no character has the blueprints scope.
     *
     * @param  array<int, int|string>  $formulaTypeIds
     * @return array{has_blueprint_scope: bool, stock: array<int, int>}
     */
    public function formulaStock(User $user, array $formulaTypeIds): array
    {
        $hasBlueprintScope = $this->formulas->userHasBlueprintScope($user);

        $stock = [];
        if ($hasBlueprintScope) {
            $ownedFormulas = $this->formulas->getOwnedFormulas($user);
            foreach ($formulaTypeIds as $typeId) {
                $entry = $ownedFormulas[$typeId] ?? null;
                $stock[$typeId] = is_array($entry) ? ($entry['quantity'] ?? 0) : 0;
            }
        }

        return ['has_blueprint_scope' => $hasBlueprintScope, 'stock' => $stock];
    }
}
