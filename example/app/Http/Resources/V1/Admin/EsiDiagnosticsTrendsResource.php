<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Time series for a range. Built from an array. Missing budget values stay null.
 */
class EsiDiagnosticsTrendsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string `hour`, or `minute` for ranges of six hours or less (budget series only). */
            'granularity' => $this->resource['granularity'],
            /** @var list<array{at: string, requests: int, errors: int, status_401: int, status_403: int, status_404: int, throttled_420: int, throttled_429: int, local_deferrals: int, cache_hits: int}> Hourly upstream attempts and errors. */
            'buckets' => $this->resource['buckets'],
            /** @var list<array{at: string, remaining_min: int|null, protection_episodes: int}> Lowest legacy remaining errors observed. */
            'legacy_budget' => $this->resource['legacy_budget'],
            /** @var list<array{group: string, points: list<array{at: string, remaining_min: int|null, used: int|null}>}> One series per advertised token group. */
            'token_groups' => $this->resource['token_groups'],
        ];
    }
}
