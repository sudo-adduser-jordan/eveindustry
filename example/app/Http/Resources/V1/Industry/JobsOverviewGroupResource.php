<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The active jobs of one activity, soonest to finish first.
 */
class JobsOverviewGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'activity_name' => (string) $this->resource['activity_name'],
            /** @var list<JobsOverviewJobResource> */
            'jobs' => JobsOverviewJobResource::collection($this->resource['jobs'])->resolve($request),
        ];
    }
}
