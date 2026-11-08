<?php

namespace App\Services\Esi\Diagnostics;

/** Ordering for Redis-style "ms-seq" stream ids. */
final class StreamId
{
    public static function compare(string $a, string $b): int
    {
        [$am, $as] = array_map('intval', explode('-', $a.'-0'));
        [$bm, $bs] = array_map('intval', explode('-', $b.'-0'));

        return $am <=> $bm ?: $as <=> $bs;
    }

    public static function milliseconds(string $id): int
    {
        return (int) explode('-', $id)[0];
    }
}
