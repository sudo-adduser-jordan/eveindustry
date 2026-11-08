<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Active industry jobs across all characters, from JobsOverviewService::forUser().
 */
class JobsOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            'total_jobs' => (int) $o['total_jobs'],
            /** @var list<JobsOverviewGroupResource> One group per activity that has jobs. */
            'groups' => JobsOverviewGroupResource::collection($o['groups'])->resolve($request),
            /** @var list<JobsOverviewItemResource> Manufacturing and reaction output per item, by name. */
            'summary' => JobsOverviewItemResource::collection($o['summary'])->resolve($request),
            /** Value of all summarised output; null when nothing is priced. */
            'estimated_total' => isset($o['estimated_total']) ? (float) $o['estimated_total'] : null,
        ];
    }
}
