<?php

namespace App\Jobs;

use App\Models\Character;
use App\Services\CharacterRefreshService;
use App\Services\Esi\EsiRequestException;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RefreshCharacterProfile implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 1800;

    public array $backoff = [30, 60];

    private int $queuedAt;

    public function __construct(public readonly int $characterId)
    {
        $this->queuedAt = now()->timestamp;
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->characterId;
    }

    public function uniqueVia(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('esi.coordination_store'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('character-profile.'.$this->characterId))->releaseAfter(30)->expireAfter(210)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->queuedAt + 1800);
    }

    public function handle(CharacterRefreshService $profiles): void
    {
        $character = Character::whereKey($this->characterId)->where('is_esi_active', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('last_login_at', '>=', now()->subDays(7)))
            ->first();
        if (! $character) {
            return;
        }
        try {
            $profiles->refresh($character);
        } catch (EsiRequestException $e) {
            if ($e->retryable()) {
                $this->release(max(1, $e->retryAfterSeconds) + random_int(1, 5));
            }
        }
    }
}
