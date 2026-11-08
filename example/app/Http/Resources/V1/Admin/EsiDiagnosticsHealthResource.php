<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Collector health. When not healthy, empty charts must be read as incomplete rather than clean.
 */
class EsiDiagnosticsHealthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var bool */
            'enabled' => $r['enabled'],
            /** @var bool */
            'healthy' => $r['healthy'],
            /** @var list<string> `collector_stale`, `buffer_unreachable`, `buffer_near_cap` or `uncounted_loss`. */
            'problems' => $r['problems'],
            /** @var string|null UTC */
            'last_flushed_at' => $r['last_flushed_at'],
            /** @var int|null */
            'seconds_since_flush' => $r['seconds_since_flush'],
            /** @var int|null Events waiting in the buffer; null when it cannot be reached. */
            'backlog' => $r['backlog'],
            /** @var int|null */
            'oldest_event_age_seconds' => $r['oldest_event_age_seconds'],
            /** @var int Events lost before reaching SQL. */
            'dropped_events' => $r['dropped_events'],
            /** @var int */
            'gap_count' => $r['gap_count'],
            /** @var string|null UTC */
            'last_gap_at' => $r['last_gap_at'],
            /** @var string|null */
            'last_gap_reason' => $r['last_gap_reason'],
            /** @var int Rows deliberately expired by retention (not a loss). */
            'expired_rows' => $r['expired_rows'],
        ];
    }
}
