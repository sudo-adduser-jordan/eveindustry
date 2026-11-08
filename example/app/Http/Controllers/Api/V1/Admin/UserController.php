<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UserIndexRequest;
use App\Http\Requests\Api\V1\Admin\UserIndustryJobsRequest;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Admin\AdminUserResource;
use App\Http\Resources\V1\Admin\UserIndustryJobsResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Admin\UserJobsOverviewService;
use App\Services\MarketService;
use App\Services\MarketTradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UserController extends Controller
{
    /**
     * List users.
     *
     * Searchable by account name, email or character name, sortable, 20 a
     * page by default. Admins only.
     *
     * @response array{data: AdminUserResource[], meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(UserIndexRequest $request): JsonResponse
    {
        $query = User::with(['mainCharacter'])->withCount('characters');

        if ($search = $request->validated('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('characters', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderBy($request->validated('sort') ?? 'created_at', $request->validated('direction') ?? 'desc');

        return ApiResponse::paginated($query->paginate($request->perPage(), page: $request->page()), AdminUserResource::class);
    }

    /**
     * Activate or deactivate a user. Super admins cannot be changed (403).
     *
     * @response array{data: AdminUserResource, meta: object}
     */
    public function toggleStatus(NoInputRequest $request, User $user): JsonResponse
    {
        abort_if($user->is_super_admin, 403, 'Cannot modify super admin.');
        $user->update(['is_active' => ! $user->is_active]);

        return $this->respond($user);
    }

    /**
     * Promote a user to admin or demote them. Super admins cannot be changed (403).
     *
     * @response array{data: AdminUserResource, meta: object}
     */
    public function toggleAdmin(NoInputRequest $request, User $user): JsonResponse
    {
        abort_if($user->is_super_admin, 403, 'Cannot modify super admin.');
        $user->update(['is_admin' => ! $user->is_admin]);

        return $this->respond($user);
    }

    /**
     * Turn the Industry Stock Manager on or off for a user.
     *
     * @response array{data: AdminUserResource, meta: object}
     */
    public function toggleStockManager(NoInputRequest $request, User $user): JsonResponse
    {
        $user->update(['is_stock_manager_enabled' => ! $user->is_stock_manager_enabled]);

        return $this->respond($user);
    }

    /**
     * Delete a user and their characters for good. Super admins cannot be deleted (403).
     */
    public function destroy(NoInputRequest $request, User $user): Response
    {
        abort_if($user->is_super_admin, 403, 'Cannot delete super admin.');
        $user->delete();

        return response()->noContent();
    }

    /**
     * Active industry jobs of a user.
     *
     * Grouped by activity with expected output, valued at the sell prices of
     * the chosen hub, plus a production summary by item.
     *
     * @response array{data: UserIndustryJobsResource, meta: object}
     */
    public function industryJobs(UserIndustryJobsRequest $request, User $user, UserJobsOverviewService $overview): JsonResponse
    {
        $result = $overview->forUser($user->id, (int) ($request->validated('region_id') ?? MarketService::DEFAULT_REGION_ID));

        return ApiResponse::success(new UserIndustryJobsResource($result + [
            'user' => $user,
            'regions' => collect(MarketTradingService::REGIONS)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all(),
        ]));
    }

    private function respond(User $user): JsonResponse
    {
        return ApiResponse::success(new AdminUserResource($user->fresh(['mainCharacter'])->loadCount('characters')));
    }
}
