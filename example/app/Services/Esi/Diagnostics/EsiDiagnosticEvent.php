<?php

namespace App\Services\Esi\Diagnostics;

use Illuminate\Support\Str;

/** Bounded scalar event. Unknown keys are discarded, so no payload can ride along. */
final class EsiDiagnosticEvent
{
    public const KINDS = ['http_attempt', 'connection_failure', 'circuit_opened', 'circuit_closed', 'local_deferral', 'cache_hit', 'cache_miss', 'cache_revalidation'];

    /** column => max length for strings, 'int' for integers, 'ts' for timestamps */
    private const SCHEMA = [
        'kind' => 24, 'method' => 7, 'operation_key' => 160, 'compatibility_date' => 10, 'dataset' => 48, 'job_class' => 96,
        'correlation_id' => 64, 'source_identity' => 48, 'application_id' => 48, 'character_id' => 'int', 'corporation_id' => 'int',
        'resource_id' => 'int', 'page' => 'int', 'batch_size' => 'int', 'http_status' => 'int', 'reason' => 32, 'duration_ms' => 'int',
        'response_received' => 'bool', 'legacy_remaining' => 'int', 'legacy_reset_seconds' => 'int', 'legacy_reset_at' => 'ts',
        'limiter_group' => 64, 'token_limit' => 32, 'token_used' => 'int', 'token_remaining' => 'int', 'retry_after_seconds' => 'int',
        'upstream_request_id' => 64, 'batch_fingerprint' => 64, 'release' => 40,
    ];

    /** Every column of a stored event, in a stable order. */
    public static function columns(): array
    {
        return array_merge(['event_id', 'occurred_at'], array_keys(self::SCHEMA));
    }

    public static function make(array $attributes): array
    {
        $event = ['event_id' => (string) Str::ulid(), 'occurred_at' => self::now()];
        foreach (self::SCHEMA as $column => $type) {
            $value = $attributes[$column] ?? null;
            $event[$column] = $value === null || $value === '' ? null : match (true) {
                $type === 'int' => is_numeric($value) ? (int) $value : null,
                $type === 'bool' => (bool) $value,
                $type === 'ts' => is_int($value) ? gmdate('Y-m-d H:i:s', $value) : null,
                default => mb_substr((string) $value, 0, $type),
            };
        }
        if (! in_array($event['kind'], self::KINDS, true)) {
            $event['kind'] = 'http_attempt';
        }
        if (! in_array($event['reason'], EsiErrorClassifier::REASONS, true)) {
            $event['reason'] = 'unknown';
        }
        $event['operation_key'] ??= '';
        $event['source_identity'] ??= '';
        $event['application_id'] ??= '';
        $event['response_received'] ??= false;

        return $event;
    }

    private static function now(): string
    {
        $micro = microtime(true);

        return gmdate('Y-m-d H:i:s', (int) $micro).sprintf('.%06d', ($micro - floor($micro)) * 1_000_000);
    }
}
