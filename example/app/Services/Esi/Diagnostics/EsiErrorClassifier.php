<?php

namespace App\Services\Esi\Diagnostics;

/** Maps a response to a small, stable reason allowlist. The message is inspected in memory and never kept. */
final class EsiErrorClassifier
{
    public const REASONS = [
        'ok', 'not_modified', 'missing_scope', 'missing_role', 'corporation_mismatch', 'credential', 'forbidden',
        'not_found', 'invalid_id_batch', 'throttled_420', 'throttled_429', 'upstream_failure', 'client_error',
        'connection', 'error_limited', 'rate_limited', 'circuit_open', 'circuit_expired_inferred', 'cache', 'unknown',
    ];

    public static function classify(?int $status, ?string $message = null): string
    {
        if ($status === null) {
            return 'connection';
        }
        if ($status === 304) {
            return 'not_modified';
        }
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status === 420) {
            return 'throttled_420';
        }
        if ($status === 429) {
            return 'throttled_429';
        }
        if ($status >= 500) {
            return 'upstream_failure';
        }
        $text = strtolower((string) $message);
        if ($text !== '') {
            if (str_contains($text, 'scope')) {
                return 'missing_scope';
            }
            if (str_contains($text, 'role')) {
                return 'missing_role';
            }
            if (str_contains($text, 'corporation') && (str_contains($text, 'member') || str_contains($text, 'mismatch') || str_contains($text, 'not '))) {
                return 'corporation_mismatch';
            }
            if ($status === 400 && (str_contains($text, 'invalid') || str_contains($text, ' ids') || str_contains($text, 'ids '))) {
                return 'invalid_id_batch';
            }
        }

        return match (true) {
            $status === 401 => 'credential',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status >= 400 => 'client_error',
            default => 'unknown',
        };
    }
}
