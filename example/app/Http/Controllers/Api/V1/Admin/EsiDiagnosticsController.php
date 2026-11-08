<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\EsiDiagnosticsRequest;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Admin\EsiDiagnosticBatchResource;
use App\Http\Resources\V1\Admin\EsiDiagnosticEventResource;
use App\Http\Resources\V1\Admin\EsiDiagnosticsEndpointsResource;
use App\Http\Resources\V1\Admin\EsiDiagnosticsHealthResource;
use App\Http\Resources\V1\Admin\EsiDiagnosticsSummaryResource;
use App\Http\Resources\V1\Admin\EsiDiagnosticsTrendsResource;
use App\Http\Responses\ApiResponse;
use App\Services\Admin\EsiDiagnosticsReportService;
use App\Services\Esi\Diagnostics\EsiDiagnosticsHealth;
use Illuminate\Http\JsonResponse;

/**
 * Admin-only reports over stored ESI diagnostics. All times are UTC.
 */
class EsiDiagnosticsController extends Controller
{
    /**
     * ESI diagnostics summary.
     *
     * Upstream requests, error percentage, 401/403/404 and upstream 420/429
     * counts, protection episodes and local deferrals for the range (default
     * last 24 hours, at most 31 days). Admins only.
     *
     * @response array{data: EsiDiagnosticsSummaryResource, meta: object}
     */
    public function summary(EsiDiagnosticsRequest $request, EsiDiagnosticsReportService $reports): JsonResponse
    {
        [$from, $to] = $request->range();

        return ApiResponse::success(new EsiDiagnosticsSummaryResource($reports->summary($from, $to, $request->filters())));
    }

    /**
     * ESI diagnostics trends.
     *
     * Error counts over time beside the lowest observed legacy remaining
     * budget, plus one series per token group. Admins only.
     *
     * @response array{data: EsiDiagnosticsTrendsResource, meta: object}
     */
    public function trends(EsiDiagnosticsRequest $request, EsiDiagnosticsReportService $reports): JsonResponse
    {
        [$from, $to] = $request->range();

        return ApiResponse::success(new EsiDiagnosticsTrendsResource($reports->trends($from, $to, $request->filters())));
    }

    /**
     * ESI diagnostics top endpoints and reasons.
     *
     * By error count, with raw counts beside errors per 1,000 upstream
     * requests. Admins only.
     *
     * @response array{data: EsiDiagnosticsEndpointsResource, meta: object}
     */
    public function endpoints(EsiDiagnosticsRequest $request, EsiDiagnosticsReportService $reports): JsonResponse
    {
        [$from, $to] = $request->range();

        return ApiResponse::success(new EsiDiagnosticsEndpointsResource($reports->endpoints($from, $to, $request->filters())));
    }

    /**
     * ESI diagnostics failure detail.
     *
     * Recent stored failures and circuit transitions, newest first, with
     * cursor pagination: pass `meta.next_cursor` as `before_id`. Admins only.
     *
     * @response array{data: EsiDiagnosticEventResource[], meta: array{next_cursor: int|null}}
     */
    public function events(EsiDiagnosticsRequest $request, EsiDiagnosticsReportService $reports): JsonResponse
    {
        [$from, $to] = $request->range();
        $page = $reports->events($from, $to, $request->filters(), $request->validated('before_id'), $request->perPage());

        return ApiResponse::success(EsiDiagnosticEventResource::collection($page['rows']), ['next_cursor' => $page['next_cursor']]);
    }

    /**
     * ESI diagnostics repeated batches.
     *
     * Batch fingerprints seen more than once. A repeated fingerprint marks a
     * recurring batch, not which item in it is invalid. Admins only.
     *
     * @response array{data: EsiDiagnosticBatchResource[], meta: object}
     */
    public function batches(EsiDiagnosticsRequest $request, EsiDiagnosticsReportService $reports): JsonResponse
    {
        [$from, $to] = $request->range();

        return ApiResponse::success(EsiDiagnosticBatchResource::collection($reports->batches($from, $to, $request->filters())));
    }

    /**
     * ESI diagnostics capture health.
     *
     * Last ingestion, backlog, oldest event age, dropped events and gaps.
     * Admins only.
     *
     * @response array{data: EsiDiagnosticsHealthResource, meta: object}
     */
    public function health(NoInputRequest $request, EsiDiagnosticsHealth $health): JsonResponse
    {
        return ApiResponse::success(new EsiDiagnosticsHealthResource($health->report()));
    }
}
