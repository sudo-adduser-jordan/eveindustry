<?php

namespace App\Services\Esi\Diagnostics\Streams;

/** Capped ordered buffer between the request path and the SQL collector. */
interface EsiDiagnosticsStream
{
    /** Append one serialized event. Throws if the buffer is unavailable. */
    public function add(string $json): void;

    /**
     * Entries strictly after $afterId (all when null), oldest first.
     *
     * @return array<string, string> stream id => serialized event
     */
    public function read(?string $afterId, int $count): array;

    public function length(): int;

    public function oldestId(): ?string;

    /** Entries ever added in this buffer lifetime (survives trimming). Null when the backend cannot say. */
    public function totalAdded(): ?int;

    /** Identifies this buffer's lifetime; changes if the buffer is flushed or recreated. */
    public function epoch(): string;

    /** Discard entries up to and including $id. Only called after the SQL commit. */
    public function trimThrough(string $id): void;

    public function recordDrop(): void;

    public function pendingDrops(): int;

    public function acknowledgeDrops(int $count): void;
}
