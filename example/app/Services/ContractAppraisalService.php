<?php

namespace App\Services;

use App\Models\EveType;
use Illuminate\Support\Facades\DB;

class ContractAppraisalService
{
    /**
     * Parse raw text (one item name per line) and return appraisal results.
     *
     * For each unique item, we find item_exchange contracts where that item type
     * is the only type in the contract and average the per-unit contract price.
     *
     * @param  string  $filter  'all', 'sell', or 'buy'
     * @param  string  $method  'average' (mean per-unit price) or 'lowest' (cheapest per-unit price)
     * @return array{items: array, total_value: float, unmatched: string[]}
     */
    public function appraise(string $rawText, string $filter = 'all', string $method = 'average'): array
    {
        $parsed = $this->parseItems($rawText);
        $items = [];
        $unmatched = [];
        $total = 0.0;

        if (empty($parsed)) {
            return ['items' => [], 'total_value' => 0, 'unmatched' => []];
        }

        // Resolve item names to type IDs
        $names = array_keys($parsed);
        $eveTypes = EveType::whereIn('name', $names)->get()->keyBy('name');

        foreach ($parsed as $name => $quantity) {
            $eveType = $eveTypes->get($name);

            if (! $eveType) {
                $unmatched[] = $name;

                continue;
            }

            $pricing = $this->getContractPricing($eveType->type_id, $filter, $method);

            $lineTotal = $pricing['unit_price'] * $quantity;
            $total += $lineTotal;

            $items[] = [
                'name' => $eveType->name,
                'type_id' => $eveType->type_id,
                'quantity' => $quantity,
                'unit_price' => $pricing['unit_price'],
                'line_total' => $lineTotal,
                'contracts_found' => $pricing['contracts_found'],
            ];
        }

        return [
            'items' => $items,
            'total_value' => $total,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Parse raw text into an associative array of item name => quantity.
     *
     * Duplicate lines are aggregated.
     *
     * @return array<string, int>
     */
    public function parseItems(string $rawText): array
    {
        $lines = preg_split('/\r?\n/', $rawText);
        $items = [];

        foreach ($lines as $line) {
            $name = trim($line);

            if ($name === '') {
                continue;
            }

            $items[$name] = ($items[$name] ?? 0) + 1;
        }

        return $items;
    }

    /**
     * Find item_exchange contracts where the given type is the ONLY item type
     * in the contract and return per-unit pricing.
     *
     * @param  string  $filter  'all', 'sell' (is_included=true), or 'buy' (is_included=false)
     * @param  string  $method  'average' (mean per-unit price) or 'lowest' (cheapest per-unit price)
     * @return array{unit_price: float, contracts_found: int}
     */
    public function getContractPricing(int $typeId, string $filter = 'all', string $method = 'average'): array
    {
        $generationId = DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        if ($generationId === null) {
            return ['unit_price' => 0.0, 'contracts_found' => 0];
        }

        // Subquery: contract IDs that contain exactly one distinct type_id
        // and that type_id matches the requested one.
        $singleTypeContracts = DB::table('public_contract_snapshot_items as pci')
            ->select('pci.contract_id')
            ->join('public_contract_snapshot_contracts as pc', function ($join) {
                $join->on('pc.contract_id', '=', 'pci.contract_id')
                    ->on('pc.generation_id', '=', 'pci.generation_id');
            })
            ->where('pc.generation_id', $generationId)
            ->where('pci.generation_id', $generationId)
            ->where('pc.type', 'item_exchange');

        if ($filter === 'sell') {
            $singleTypeContracts->where('pci.is_included', true);
        } elseif ($filter === 'buy') {
            $singleTypeContracts->where('pci.is_included', false);
        }

        $singleTypeContracts
            ->groupBy('pci.contract_id')
            ->havingRaw('COUNT(DISTINCT pci.type_id) = 1')
            ->havingRaw('MIN(pci.type_id) = ?', [$typeId]);

        // Whitelist the SQL aggregate to a fixed set — never interpolate caller input directly.
        $aggregate = match ($method) {
            'lowest' => 'MIN',
            default => 'AVG',
        };

        $result = DB::table('public_contract_snapshot_contracts as pc')
            ->joinSub($singleTypeContracts, 'matched', 'pc.contract_id', '=', 'matched.contract_id')
            ->where('pc.generation_id', $generationId)
            ->selectRaw("COUNT(*) as contract_count, {$aggregate}(pc.price / NULLIF((SELECT SUM(pci2.quantity) FROM public_contract_snapshot_items pci2 WHERE pci2.generation_id = pc.generation_id AND pci2.contract_id = pc.contract_id), 0)) as unit_price")
            ->first();

        return [
            'unit_price' => round((float) ($result->unit_price ?? 0), 2),
            'contracts_found' => (int) ($result->contract_count ?? 0),
        ];
    }
}
