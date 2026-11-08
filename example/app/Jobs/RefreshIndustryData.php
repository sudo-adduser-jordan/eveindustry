<?php

namespace App\Jobs;

use App\Services\Esi\EsiRequestException;
use App\Services\IndustryService;
use App\Services\RefreshEligibility;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RefreshIndustryData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    public array $backoff = [60, 120, 300];

    public int $maxExceptions = 3;

    private int $queuedAt;

    public function __construct(protected int $userId)
    {
        $this->queuedAt = now()->timestamp;
    }

    public function uniqueVia(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('esi.coordination_store'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestamp(($this->queuedAt ?? now()->timestamp) + 1800);
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('industry-refresh:'.$this->userId))->releaseAfter(30)->expireAfter(150)];
    }

    public function handle(IndustryService $industryService): void
    {
        if (! RefreshEligibility::user($this->userId, 'industry')) {
            return;
        }

        try {
            $industryService->refreshForUser($this->userId);
        } catch (EsiRequestException $e) {
            if ($e->retryable() && $this->job !== null) {
                $this->release(max(1, $e->retryAfterSeconds) + random_int(1, 5));

                return;
            }
            throw $e;
        }
    }
}
