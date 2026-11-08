<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\CorporationListRequest;
use App\Http\Requests\Api\V1\Corp\ProjectContributorsRequest;
use App\Http\Resources\V1\Corp\ProjectContributorResource;
use App\Http\Resources\V1\Corp\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Services\CorporationProjectsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    public function __construct(private CorporationProjectsService $projects) {}

    /**
     * Corporation projects.
     *
     * The corporation's projects, most recently modified first, with progress,
     * reward and contributors. Needs a main character in a corporation with
     * the esi-corporations.read_projects.v1 scope; otherwise 403.
     * `meta.summary` holds totals across all projects.
     *
     * @response array{data: list<ProjectResource>, meta: array{summary: array{corporation_id: int|null, corporation_name: string|null, total_projects: int, completed_projects: int, in_progress_projects: int, total_progress: int, total_desired: int, overall_completion_pct: float, total_reward_initial: float, total_reward_remaining: float, total_reward_earned: float}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(CorporationListRequest $request): JsonResponse
    {
        $result = $this->projects->getProjectsForUser((int) $request->user()->id);

        return ApiResponse::paginatedArray($result['projects'], $request->page(), $request->perPage(), ProjectResource::class, [
            'summary' => [
                'corporation_id' => $result['corporation_id'] === null ? null : (int) $result['corporation_id'],
                'corporation_name' => $result['corporation_name'],
                'total_projects' => (int) $result['total_projects'],
                'completed_projects' => (int) $result['completed_projects'],
                'in_progress_projects' => (int) $result['in_progress_projects'],
                'total_progress' => (int) $result['total_progress'],
                'total_desired' => (int) $result['total_desired'],
                'overall_completion_pct' => (float) $result['overall_completion_pct'],
                'total_reward_initial' => (float) $result['total_reward_initial'],
                'total_reward_remaining' => (float) $result['total_reward_remaining'],
                'total_reward_earned' => (float) $result['total_reward_earned'],
            ],
        ]);
    }

    /**
     * Project contributor leaderboard.
     *
     * ISK contribution rankings over the corporation's rewarded projects that
     * are Closed or Completed, largest first. Each contributor's ISK is the
     * units contributed times the project's reward per unit. Same 403 as
     * `GET /corporation/projects`.
     *
     * @response array{data: list<ProjectContributorResource>, meta: array{summary: array{corporation_name: string|null, total_attributed_isk: float, total_rewarded_projects: int, date_from: string|null, date_to: string|null, project_name: string|null}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function contributors(ProjectContributorsRequest $request): JsonResponse
    {
        $result = $this->projects->buildContributorLeaderboard(
            (int) $request->user()->id,
            $request->dateFrom(),
            $request->dateTo(),
            $request->projectName(),
        );

        return ApiResponse::paginatedArray($result['contributors'], $request->page(), $request->perPage(), ProjectContributorResource::class, [
            'summary' => [
                'corporation_name' => $result['corporation_name'],
                'total_attributed_isk' => (float) $result['total_attributed_isk'],
                'total_rewarded_projects' => (int) $result['total_rewarded_projects'],
                'date_from' => $result['date_from'],
                'date_to' => $result['date_to'],
                'project_name' => $result['project_name'],
            ],
        ]);
    }

    /**
     * Refresh project data.
     *
     * Forgets the cached projects and contributors of the corporation, so the
     * next read asks ESI again. Returns 204.
     */
    public function refresh(Request $request): Response
    {
        $this->projects->clearCache((int) $request->user()->id);

        return response()->noContent();
    }
}
