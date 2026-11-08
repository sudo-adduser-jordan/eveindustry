<?php

namespace App\Services\Esi\Diagnostics\Streams;

use App\Services\Esi\Diagnostics\StreamId;

/** In-memory stream with Redis Stream semantics, for tests and local runs. */
class ArrayStream implements EsiDiagnosticsStream
{
    /** @var array<string, string> */
    private array $entries = [];

    private int $sequence = 0;

    private string $epoch;

    private int $drops = 0;

    public function __construct(private int $maxEntries = 100000)
    {
        $this->epoch = bin2hex(random_bytes(8));
    }

    public function add(string $json): void
    {
        $this->entries[(1_000_000 + ++$this->sequence).'-0'] = $json;
        while (count($this->entries) > $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    public function read(?string $afterId, int $count): array
    {
        $out = [];
        foreach ($this->entries as $id => $json) {
            if ($afterId === null || StreamId::compare($id, $afterId) > 0) {
                $out[$id] = $json;
                if (count($out) >= $count) {
                    break;
                }
            }
        }

        return $out;
    }

    public function length(): int
    {
        return count($this->entries);
    }

    public function oldestId(): ?string
    {
        return array_key_first($this->entries);
    }

    public function totalAdded(): ?int
    {
        return $this->sequence;
    }

    public function epoch(): string
    {
        return $this->epoch;
    }

    public function trimThrough(string $id): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (StreamId::compare($key, $id) <= 0) {
                unset($this->entries[$key]);
            }
        }
    }

    public function recordDrop(): void
    {
        $this->drops++;
    }

    public function pendingDrops(): int
    {
        return $this->drops;
    }

    public function acknowledgeDrops(int $count): void
    {
        $this->drops = max(0, $this->drops - $count);
    }

    /** Test helper: discard the oldest entries unread, as an over-full cap would. */
    public function evictOldest(int $count): void
    {
        foreach (array_slice(array_keys($this->entries), 0, $count) as $id) {
            unset($this->entries[$id]);
        }
    }

    /** Test helper: simulate the Redis keyspace being flushed or replaced. */
    public function reset(): void
    {
        $this->entries = [];
        $this->sequence = 0;
        $this->epoch = bin2hex(random_bytes(8));
    }
}
