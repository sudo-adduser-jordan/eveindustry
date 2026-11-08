<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user's active industry jobs by activity, from UserJobsOverviewService.
 */
class UserIndustryJobsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'user' => ['id' => (int) $r['user']->id, 'display_name' => (string) $r['user']->display_name],
            'region_id' => (int) $r['region_id'],
            /** @var list<array{id: int, name: string}> Trade hubs a price region can be picked from. */
            'regions' => $r['regions'],
            /** @var list<array{activity_name: string, jobs: list<array{job_id: int, character_name: string, type_id: int|null, item_name: string, item_image_url: string|null, runs: int, expected_output: int|float, estimated_value: float|null, time_remaining: string, progress_pct: int|float, ready_for_delivery: bool, location: string}>}> */
            'groups' => $r['groups'],
            /** @var list<array{type_id: int|null, item_name: string, item_image_url: string|null, total_runs: int, total_expected_output: int, job_count: int, estimated_value: float|null}> Manufacturing and reaction output by item. */
            'summary' => $r['summary'],
            /** @var float|null Estimated value of the whole production summary, null when nothing is priced. */
            'grand_total' => $r['grand_total'],
        ];
    }
}
