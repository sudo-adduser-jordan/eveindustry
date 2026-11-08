<?php

namespace App\Services;

use App\Services\Esi\EsiRequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/** Shared floating token budget; reservations use the maximum ordinary request cost. */
class EsiRateLimiter
{
    public const DEFAULT_WINDOW_SECONDS = 900;

    public const DEFAULT_LIMIT = 600;

    public function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('esi.coordination_store', 'esi_coordination'));
    }

    public function tryConsume(string $group, ?string $userId): bool
    {
        return $this->reserve($group, $userId, 2) !== null;
    }

    public function reserve(string $group, ?string $userId, int $cost = 5): ?string
    {
        $key = $this->buildKey($group, $userId);
        try {
            return $this->store()->lock('lock.'.$key, 10)->block(2, function () use ($key, $group, $userId, $cost) {
                if ($this->resetsInSeconds($group, $userId, $cost) > 0) {
                    return null;
                }
                $config = $this->getLimitConfig($group);
                $entries = $this->activeEntries($key, $config['window']);
                $id = bin2hex(random_bytes(12));
                $entries[] = ['id' => $id, 'at' => now()->getTimestampMs() / 1000, 'cost' => $cost, 'pending' => true];
                $this->store()->put($key, $entries, $config['window'] + 60);
                $remote = $this->store()->get($key.'.remote');
                if ($remote !== null && $remote['until'] > now()->timestamp) {
                    $remote['remaining'] = max(0, $remote['remaining'] - $cost);
                    $this->store()->put($key.'.remote', $remote, max(1, $remote['until'] - now()->timestamp));
                }

                return $id;
            });
        } catch (\Throwable $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }

    public function reconcile(string $group, ?string $userId, string $reservation, Response $response): void
    {
        $key = $this->buildKey($group, $userId);
        try {
            $this->store()->lock('lock.'.$key, 10)->block(2, function () use ($key, $group, $reservation, $response) {
                $config = $this->getLimitConfig($group);
                $entries = $this->activeEntries($key, $config['window']);
                $status = $response->status();
                $cost = $status >= 500 || $status === 429 ? 0 : ($status >= 400 ? 5 : ($status >= 300 ? 1 : 2));
                $reserved = 0;
                $used = $response->header('X-Ratelimit-Used');
                if ($used !== null && ctype_digit($used)) {
                    $cost = (int) $used;
                }
                foreach ($entries as &$entry) {
                    if ($entry['id'] === $reservation) {
                        $reserved = $entry['cost'];
                        $entry['cost'] = $cost;
                        $entry['pending'] = false;
                    }
                }
                unset($entry);
                $this->store()->put($key, $entries, $config['window'] + 60);
                // Remote remaining is a conservative ceiling. Never increase it from
                // out-of-order responses; expire it after a full upstream window.
                $remaining = $response->header('X-Ratelimit-Remaining');
                if ($remaining !== null && ctype_digit($remaining)) {
                    $ceilingKey = $key.'.remote';
                    $prior = $this->store()->get($ceilingKey);
                    $pending = array_sum(array_column(array_filter($entries, fn ($entry) => $entry['pending'] ?? false), 'cost'));
                    $ceiling = $prior === null
                        ? max(0, (int) $remaining - $pending)
                        : min((int) $remaining, max(0, $prior['remaining'] + $reserved - $cost));
                    $until = $prior['until'] ?? now()->timestamp + $config['window'];
                    $this->store()->put($ceilingKey, ['remaining' => $ceiling, 'until' => $until], max(1, $until - now()->timestamp));
                }
                $advertised = $response->header('X-Ratelimit-Limit');
                if ($advertised && preg_match('/^(\d+)\/(\d+)([mh])$/', $advertised, $match)) {
                    $this->store()->put('esi.rl.config.'.$group, ['limit' => (int) $match[1], 'window' => (int) $match[2] * ($match[3] === 'h' ? 3600 : 60)], 86400);
                }
                if ($status === 429) {
                    $value = $response->header('Retry-After');
                    $delay = ctype_digit((string) $value) ? (int) $value : max(0, (int) strtotime((string) $value) - now()->timestamp);
                    $deadline = now()->timestamp + ($value !== null && $value !== '' ? max(1, $delay) : 60);
                    $deadline = max($deadline, (int) $this->store()->get($key.'.cooldown', 0));
                    $this->store()->put($key.'.cooldown', $deadline, max(1, $deadline - now()->timestamp));
                }
            });
        } catch (\Throwable $e) {
            throw new EsiRequestException('coordination', 5);
        }
    }

    public function isLimited(string $group, ?string $userId): bool
    {
        return $this->resetsInSeconds($group, $userId) > 0;
    }

    public function remaining(string $group, ?string $userId): int
    {
        $key = $this->buildKey($group, $userId);
        $config = $this->getLimitConfig($group);
        $local = max(0, $config['limit'] - array_sum(array_column($this->activeEntries($key, $config['window']), 'cost')));
        $remote = $this->store()->get($key.'.remote');

        return min($local, $remote !== null && $remote['until'] > now()->timestamp ? $remote['remaining'] : PHP_INT_MAX);
    }

    public function resetsInSeconds(string $group, ?string $userId, int $cost = 2): int
    {
        $key = $this->buildKey($group, $userId);
        $cooldown = max(0, (int) $this->store()->get($key.'.cooldown', 0) - now()->timestamp);
        if ($this->remaining($group, $userId) >= $cost) {
            return $cooldown;
        }
        $config = $this->getLimitConfig($group);
        $entries = $this->activeEntries($key, $config['window']);
        $first = $entries === [] ? now()->timestamp : min(array_column($entries, 'at'));
        $remote = $this->store()->get($key.'.remote');
        $remoteWait = $remote && $remote['remaining'] < $cost ? $remote['until'] - now()->timestamp : 0;

        return max(1, $cooldown, $remoteWait, (int) ceil($first + $config['window'] - now()->getTimestampMs() / 1000));
    }

    public function getLimitConfig(string $group): array
    {
        return $this->store()->get('esi.rl.config.'.$group) ?? config('esi_groups.'.$group, ['window' => self::DEFAULT_WINDOW_SECONDS, 'limit' => self::DEFAULT_LIMIT]);
    }

    protected function buildKey(string $group, ?string $userId): string
    {
        $app = config('esi.application_id', 'eve-industrialist');
        $identity = $userId === null ? 'ip:'.config('esi.source_identity', 'shared-egress') : 'character:'.$app.':'.$userId;

        return 'esi.rl.'.hash('sha256', $group.'|'.$identity);
    }

    protected function activeEntries(string $key, int $window): array
    {
        return array_values(array_filter($this->store()->get($key, []), fn ($entry) => $entry['at'] > now()->getTimestampMs() / 1000 - $window));
    }
}
