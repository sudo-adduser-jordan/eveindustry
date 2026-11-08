<?php

namespace App\Services\Esi\Diagnostics;

/**
 * Scoped collector context. Always use with(): the previous context is restored
 * in a finally block, and queue lifecycle hooks clear it so a long-lived worker
 * cannot leak one job's subject into the next.
 */
class EsiDiagnosticsContext
{
    public const KEYS = ['dataset', 'job_class', 'correlation_id', 'character_id', 'corporation_id', 'resource_id', 'page', 'batch_size', 'batch_fingerprint'];

    private array $values = [];

    public function with(array $values, callable $callback): mixed
    {
        $previous = $this->values;
        $this->values = array_merge($previous, array_intersect_key($values, array_flip(self::KEYS)));
        try {
            return $callback();
        } finally {
            $this->values = $previous;
        }
    }

    public function get(): array
    {
        return $this->values;
    }

    /** Set the base context for a job or command; cleared again when it ends. */
    public function replace(array $values): void
    {
        $this->values = array_intersect_key($values, array_flip(self::KEYS));
    }

    public function clear(): void
    {
        $this->values = [];
    }

    /** HMAC of a sorted ID batch: detects a repeated batch without storing its contents. */
    public static function fingerprint(array $ids): string
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids, SORT_STRING);

        return hash_hmac('sha256', implode(',', $ids), (string) config('app.key'));
    }
}
