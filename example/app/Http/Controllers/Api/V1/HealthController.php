<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\HealthService;
use App\Services\SetupStatusService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private HealthService $health, private SetupStatusService $setup) {}

    /**
     * Health.
     *
     * Public readiness check used by the post-deploy smoke test: the
     * database answers and the cache can write and read. 200 when every
     * check is `ok`, 503 when any is `failed`. No authentication.
     *
     * @unauthenticated
     *
     * `setup` shows whether a fresh install has imported its game data yet
     * (SDE item types, global prices, the first order sweep); it does not
     * affect the status code.
     *
     * @response array{data: array{status: 'ok'|'failed', checks: array{database: 'ok'|'failed', cache: 'ok'|'failed'}, setup: array{ready: bool, steps: array{sde: bool, prices: bool, orders: bool}}}, meta: object}
     */
    public function show(): JsonResponse
    {
        $result = $this->health->check() + ['setup' => $this->setup->status()];

        return ApiResponse::success($result, [], $result['status'] === HealthService::OK ? 200 : 503);
    }
}
