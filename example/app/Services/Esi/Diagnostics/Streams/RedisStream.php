<?php

namespace App\Services\Esi\Diagnostics\Streams;

use App\Services\Esi\Diagnostics\StreamId;
use Illuminate\Support\Facades\Redis;

/**
 * Capped Redis Stream on the non-evicting Redis. Uses raw commands so the same
 * code runs on phpredis and predis. The entry cap is approximate (MAXLEN ~) and
 * is not a byte limit; size the instance from measured overhead.
 */
class RedisStream implements EsiDiagnosticsStream
{
    public function __construct(
        private string $connection = 'default',
        private string $key = 'esi:diag:stream',
        private int $maxEntries = 100000,
    ) {}

    public function add(string $json): void
    {
        $this->raw(['XADD', $this->key, 'MAXLEN', '~', (string) $this->maxEntries, '*', 'e', $json]);
    }

    public function read(?string $afterId, int $count): array
    {
        $rows = $this->raw(['XRANGE', $this->key, $afterId ?? '-', '+', 'COUNT', (string) ($count + 1)]) ?: [];
        $out = [];
        foreach ($rows as [$id, $fields]) {
            if ($afterId !== null && StreamId::compare($id, $afterId) <= 0) {
                continue;
            }
            $map = [];
            for ($i = 0; $i + 1 < count($fields); $i += 2) {
                $map[$fields[$i]] = $fields[$i + 1];
            }
            if (isset($map['e'])) {
                $out[$id] = $map['e'];
            }
            if (count($out) >= $count) {
                break;
            }
        }

        return $out;
    }

    public function length(): int
    {
        return (int) $this->raw(['XLEN', $this->key]);
    }

    public function oldestId(): ?string
    {
        $rows = $this->raw(['XRANGE', $this->key, '-', '+', 'COUNT', '1']) ?: [];

        return $rows[0][0] ?? null;
    }

    /** XINFO "entries-added" (Redis 7+). XTRIM does not update max-deleted-entry-id, so that field cannot show cap evictions. */
    public function totalAdded(): ?int
    {
        try {
            $info = $this->raw(['XINFO', 'STREAM', $this->key]);
        } catch (\Throwable $e) {
            return 0; // no stream yet
        }
        for ($i = 0; $i + 1 < count($info); $i += 2) {
            if ($info[$i] === 'entries-added') {
                return (int) $info[$i + 1];
            }
        }

        return null; // Redis < 7: eviction of unread entries cannot be detected
    }

    public function epoch(): string
    {
        $this->raw(['SET', $this->key.':epoch', bin2hex(random_bytes(8)), 'NX']);

        return (string) $this->raw(['GET', $this->key.':epoch']);
    }

    public function trimThrough(string $id): void
    {
        [$ms, $seq] = array_map('intval', explode('-', $id.'-0'));
        $this->raw(['XTRIM', $this->key, 'MINID', $ms.'-'.($seq + 1)]);
    }

    public function recordDrop(): void
    {
        $this->raw(['INCR', $this->key.':drops']);
    }

    public function pendingDrops(): int
    {
        return (int) $this->raw(['GET', $this->key.':drops']);
    }

    public function acknowledgeDrops(int $count): void
    {
        if ($count > 0) {
            $this->raw(['DECRBY', $this->key.':drops', (string) $count]);
        }
    }

    private function raw(array $arguments): mixed
    {
        $client = Redis::connection($this->connection)->client();

        return $client instanceof \Redis || $client instanceof \RedisCluster
            ? $client->rawCommand(...$arguments)
            : $client->executeRaw($arguments);
    }
}
