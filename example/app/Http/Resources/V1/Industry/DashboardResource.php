<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything on the dashboard, from DashboardService and the user's saved plans.
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'industry' => [
                'in_progress' => (int) $d['industry']['in_progress'],
                'ready_jobs' => (int) $d['industry']['ready_jobs'],
                'available_slots' => (int) $d['industry']['available_slots'],
            ],
            'planetary' => [
                'total_planets' => (int) $d['planetary']['total_planets'],
                'expired_extractors' => (int) $d['planetary']['expired_extractors'],
                'full_storage' => (int) $d['planetary']['full_storage'],
            ],
            'account' => [
                'joined_at' => $d['user']->created_at?->toIso8601String(),
                'last_login_at' => $d['user']->last_login_at?->toIso8601String(),
            ],
            /** @var list<DashboardCharacterResource> Main character first. */
            'characters' => DashboardCharacterResource::collection($d['characters'])->resolve($request),
            /** @var list<DashboardPlanResource> */
            'reaction_plans' => DashboardPlanResource::collection($d['reaction_plans'])->resolve($request),
            /** @var list<DashboardPlanResource> */
            'invention_plans' => DashboardPlanResource::collection($d['invention_plans'])->resolve($request),
        ];
    }
}
