<?php

namespace App\Jobs;

use App\Services\AssetService;
use App\Services\Esi\EsiRequestException;
use App\Services\RefreshEligibility;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshAssetData implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** Re-queue with a 10-minute delay between attempts. */
    public array $backoff = [600];

    public int $uniqueFor = 1800;

    private int $queuedAt;

    /**
     * @param  bool  $force  Refresh even if the cache is still fresh (user-requested refresh)
     */
    public function __construct(public readonly int $userId, public readonly bool $force = false)
    {
        $this->queuedAt = now()->timestamp;
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueVia(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('esi.coordination_store'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestamp(($this->queuedAt ?? now()->timestamp) + 1800);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('assets.user.'.$this->userId))->releaseAfter(30)->expireAfter(210)];
    }

    public function handle(AssetService $assetService): void
    {
        if (! RefreshEligibility::user($this->userId, 'assets')) {
            return;
        }
        $assetKey = "assets.user.{$this->userId}";
        $timeKey = $assetKey.'.cached_at';
        try {
            $durableReady = ! config('assets.durable_snapshots', false)
                || ! $assetService->snapshotMetadata($this->userId)['pending'];
            if (! $this->force && $durableReady && Cache::has($assetKey)) {
                $cachedAtRaw = Cache::get($timeKey);
                if ($cachedAtRaw) {
                    try {
                        if (is_numeric($cachedAtRaw)) {
                            // Stored as unix timestamp (int)
                            $cachedAt = Carbon::createFromTimestamp((int) $cachedAtRaw);
                        } else {
                            // ISO 8601 or other date string
                            $cachedAt = Carbon::parse($cachedAtRaw);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("RefreshAssetData: Failed to parse cached_at for user {$this->userId}: {$e->getMessage()}");
                        $cachedAt = null;
                    }

                    // If the cache was updated within the last 10 minutes, skip refresh
                    if ($cachedAt && $cachedAt->gt(Carbon::now()->subMinutes(20))) {
                        Log::info("RefreshAssetData: Cache for user {$this->userId} is still fresh (cached at {$cachedAt->toIso8601String()}), skipping refresh.");

                        return;
                    }
                }
            }

            $assetService->fetchAndCacheAssets($this->userId);
        } catch (EsiRequestException $e) {
            if ($e->retryable()) {
                $this->release(max(1, $e->retryAfterSeconds) + random_int(1, 5));
            } else {
                Log::warning('Asset refresh requires access verification', ['user_id' => $this->userId, 'outcome' => $e->outcome]);
            }
        }
    }

    public function onTimeout(): void
    {
        Log::warning("RefreshAssetData: Job timed out for user {$this->userId}, will be retried after 10 minutes.");
    }
}
