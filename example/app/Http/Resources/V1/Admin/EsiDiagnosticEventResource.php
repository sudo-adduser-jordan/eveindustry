<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stored failure or circuit transition. Built from an array.
 */
class EsiDiagnosticEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var int Cursor value. */
            'id' => $r['id'],
            /** @var string UTC. */
            'occurred_at' => $r['occurred_at'],
            /** @var string `http_attempt`, `connection_failure`, `circuit_opened` or `circuit_closed`. */
            'kind' => $r['kind'],
            /** @var string|null */
            'operation' => $r['operation'],
            /** @var int|null Null when no response arrived. */
            'http_status' => $r['http_status'],
            /** @var string */
            'reason' => $r['reason'],
            /** @var string|null */
            'dataset' => $r['dataset'],
            /** @var string|null */
            'job_class' => $r['job_class'],
            /** @var string|null */
            'correlation_id' => $r['correlation_id'],
            /** @var int|null */
            'character_id' => $r['character_id'],
            /** @var string|null Present while the character still exists. */
            'character_name' => $r['character_name'],
            /** @var int|null Owning user, for the admin user context. */
            'user_id' => $r['user_id'],
            /** @var int|null */
            'corporation_id' => $r['corporation_id'],
            /** @var int|null */
            'resource_id' => $r['resource_id'],
            /** @var int|null */
            'page' => $r['page'],
            /** @var int|null */
            'batch_size' => $r['batch_size'],
            /** @var int|null */
            'duration_ms' => $r['duration_ms'],
            /** @var string|null */
            'limiter_group' => $r['limiter_group'],
            /** @var int|null Legacy remaining errors as reported; null when absent. */
            'legacy_remaining' => $r['legacy_remaining'],
            /** @var int|null */
            'retry_after_seconds' => $r['retry_after_seconds'],
            /** @var string|null */
            'upstream_request_id' => $r['upstream_request_id'],
            /** @var string|null HMAC of the ID batch. Identifies a recurring batch, not an invalid item. */
            'batch_fingerprint' => $r['batch_fingerprint'],
            /** @var string|null */
            'release' => $r['release'],
        ];
    }
}
