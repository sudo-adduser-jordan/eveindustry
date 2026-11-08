<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Headline numbers for a range. Built from an array; rates use real upstream
 * attempts, while cache hits and local deferrals are separate counts.
 */
class EsiDiagnosticsSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string Start of the range, UTC. */
            'from' => $r['from'],
            /** @var string End of the range, UTC. */
            'to' => $r['to'],
            /** @var int HTTP attempts that reached ESI, including connection failures. */
            'upstream_requests' => $r['upstream_requests'],
            /** @var int Upstream attempts that failed (4xx, 5xx or no response). */
            'errors' => $r['errors'],
            /** @var float|null Errors per 100 upstream attempts; null when there were none. */
            'error_percent' => $r['error_percent'],
            /** @var int */
            'status_401' => $r['status_401'],
            /** @var int */
            'status_403' => $r['status_403'],
            /** @var int */
            'status_404' => $r['status_404'],
            /** @var int Upstream legacy error-limit responses (420). */
            'throttled_420' => $r['throttled_420'],
            /** @var int Upstream rate-limit responses (429). */
            'throttled_429' => $r['throttled_429'],
            /** @var int Other 4xx and 5xx responses. */
            'other_errors' => $r['other_errors'],
            /** @var int Attempts with no HTTP response. */
            'connection_failures' => $r['connection_failures'],
            /** @var int Requests withheld locally; no ESI request was sent. */
            'local_deferrals' => $r['local_deferrals'],
            /** @var int */
            'cache_hits' => $r['cache_hits'],
            /** @var int */
            'cache_misses' => $r['cache_misses'],
            /** @var int */
            'cache_revalidations' => $r['cache_revalidations'],
            /** @var int Local protective pauses opened. A pause is not proof upstream remaining errors reached zero. */
            'protection_episodes' => $r['protection_episodes'],
            /** @var int Pause time observed, from inferred closes. */
            'observed_pause_seconds' => $r['observed_pause_seconds'],
            /** @var int|null Lowest legacy remaining-error value reported; null when none was reported. */
            'min_legacy_remaining' => $r['min_legacy_remaining'],
            /** @var list<array{release: string, first_seen: string}> Releases first seen on a failure in the range (deployment markers). */
            'releases' => $r['releases'],
        ];
    }
}
