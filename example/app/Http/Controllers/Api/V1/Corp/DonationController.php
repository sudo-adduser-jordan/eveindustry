<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\DonationIndexRequest;
use App\Http\Resources\V1\Corp\DonatorResource;
use App\Http\Responses\ApiResponse;
use App\Services\CorporateDonationService;
use Illuminate\Http\JsonResponse;

class DonationController extends Controller
{
    public function __construct(private CorporateDonationService $donations) {}

    /**
     * Corporation donations.
     *
     * Player donations into the corporation wallets, grouped by donator and
     * sorted by total, largest first. Needs donation tracking enabled for the
     * corporation of one of the user's characters; otherwise 403.
     * `meta.summary` holds the range, the grand total and the wallet
     * divisions. Amounts are wallet ISK, not market prices, so there is no
     * `meta.freshness`.
     *
     * @response array{data: list<DonatorResource>, meta: array{summary: array{corporation_id: int|null, corporation_name: string|null, date_from: string, date_to: string, total: float, wallet_divisions: list<array{division: int, name: string, balance: float|null}>}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(DonationIndexRequest $request): JsonResponse
    {
        $result = $this->donations->getDonationsForUser(
            (int) $request->user()->id,
            $request->dateFrom(),
            $request->dateTo(),
            $request->division(),
        );

        return ApiResponse::paginatedArray($result['donations'], $request->page(), $request->perPage(), DonatorResource::class, [
            'summary' => [
                'corporation_id' => $result['corporation_id'] === null ? null : (int) $result['corporation_id'],
                'corporation_name' => $result['corporation_name'],
                'date_from' => $result['date_from'],
                'date_to' => $result['date_to'],
                'total' => (float) $result['total'],
                'wallet_divisions' => array_map(fn (array $d) => [
                    'division' => (int) $d['division'],
                    'name' => (string) $d['name'],
                    'balance' => isset($d['balance']) ? (float) $d['balance'] : null,
                ], $result['wallet_divisions']),
            ],
        ]);
    }
}
