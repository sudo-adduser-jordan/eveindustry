<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Market\ContractIndexRequest;
use App\Http\Resources\V1\Market\ContractDetailResource;
use App\Http\Resources\V1\Market\ContractResource;
use App\Http\Responses\ApiResponse;
use App\Models\EveType;
use App\Models\PublicContract;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;

class ContractController extends Controller
{
    public function __construct(private MarketFreshnessService $freshness) {}

    /**
     * Browse public contracts.
     *
     * Contracts from the hourly import across all regions, newest first.
     * `meta.stats` counts every stored contract by type, ignoring the filters.
     *
     * @response array{data: list<ContractResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}, stats: array{total: int, item_exchange: int, auction: int, courier: int}, freshness: array{prices_as_of: string|null, costs_as_of: null, source: string, stale: bool}}}
     */
    public function index(ContractIndexRequest $request): JsonResponse
    {
        $query = PublicContract::withCount('items');

        if ($type = $request->validated('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->validated('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $contracts = $query->orderByDesc('date_issued')->orderByDesc('contract_id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $counts = PublicContract::selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type');

        return ApiResponse::paginated($contracts, ContractResource::class, [
            'stats' => [
                'total' => (int) $counts->sum(),
                'item_exchange' => (int) $counts->get('item_exchange', 0),
                'auction' => (int) $counts->get('auction', 0),
                'courier' => (int) $counts->get('courier', 0),
            ],
            'freshness' => $this->freshness->contractsFreshnessMeta(),
        ]);
    }

    /**
     * Public contract.
     *
     * One contract with its item lines (type names resolved from the SDE).
     * 404 when the contract is gone, e.g. after the next import.
     *
     * @response array{data: ContractDetailResource, meta: object}
     */
    public function show(int $contractId): JsonResponse
    {
        $contract = PublicContract::where('contract_id', $contractId)->firstOrFail();
        $items = $contract->items()->get();

        $names = EveType::whereIn('type_id', $items->pluck('type_id')->unique()->filter()->all())
            ->pluck('name', 'type_id');
        foreach ($items as $item) {
            $item->type_name = $names[$item->type_id] ?? 'Unknown Type #'.$item->type_id;
        }
        $contract->setRelation('items', $items);

        return ApiResponse::success(new ContractDetailResource($contract));
    }
}
