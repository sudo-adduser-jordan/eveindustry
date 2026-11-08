<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\CorporationBuildProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateExportController extends Controller
{
    /**
     * Whether the calculators may export to a corporate build program.
     *
     * True when your corporation has corporate build programs enabled and
     * one of your characters there is a Director, CEO or Factory Manager.
     * The calculator pages show their "corporate build program" buttons only
     * when it is.
     *
     * @response array{data: array{available: bool, corporation_name: string|null}, meta: object}
     */
    public function show(Request $request, CorporationBuildProgramService $service): JsonResponse
    {
        $user = $request->user();
        $config = $service->getConfigForUser((int) $user->id);
        $available = $config !== null && $service->canUserManage($user, (int) $config->corporation_id);

        return ApiResponse::success([
            'available' => $available,
            'corporation_name' => $available ? $config->corporation_name : null,
        ]);
    }
}
