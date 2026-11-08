<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Admin\AdminDashboardResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Admin\AdminStatsService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Admin dashboard.
     *
     * User and character counts, new registrations and logins per day over
     * 30 days, and the ten newest users. Admins only.
     *
     * @response array{data: AdminDashboardResource, meta: object}
     */
    public function show(NoInputRequest $request, AdminStatsService $stats): JsonResponse
    {
        return ApiResponse::success(new AdminDashboardResource([
            'stats' => $stats->stats(),
            'registrations' => $stats->registrationSeries(),
            'logins' => $stats->loginSeries(),
            'recent_users' => User::with('mainCharacter')->withCount('characters')->latest()->limit(10)->get(),
        ]));
    }
}
