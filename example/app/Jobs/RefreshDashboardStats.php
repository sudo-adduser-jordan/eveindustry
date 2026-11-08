<?php

namespace App\Jobs;

use App\Services\Esi\EsiRequestException;
use App\Services\IndustryService;
use App\Services\PlanetaryService;
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
use Illuminate\Support\Facades\Log;

class RefreshDashboardStats implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public array $backoff = [60, 120, 300];

    public int $maxExceptions = 3;

    /**
     * Skip recomputing when the cached stats were refreshed within this many
     * seconds. Guards against redundant work from overlapping or duplicate
     * dispatches while still allowing the scheduled refresh to run.
     */
    protected const FRESH_SECONDS = 600;

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
        return [(new WithoutOverlapping('dashboard-refresh:'.$this->userId))->releaseAfter(30)->expireAfter(210)];
    }

    public function handle(IndustryService $industryService, PlanetaryService $planetaryService): void
    {
        $failure = null;
        if (RefreshEligibility::user($this->userId, 'industry')
            && ! $this->isFresh("dashboard.industry_stats.{$this->userId}")) {
            try {
                $availability = $industryService->getAvailabilityForUser($this->userId);
                $industryStats = $this->buildIndustryStats($availability);
                $industryStats['computed_at'] = now()->timestamp;
                Cache::put("dashboard.industry_stats.{$this->userId}", $industryStats, 1200);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        if (RefreshEligibility::user($this->userId, 'pi')
            && ! $this->isFresh("dashboard.pi_stats.{$this->userId}")) {
            try {
                $piData = $planetaryService->getPlanetaryDataForUser($this->userId);
                $piStats = $this->buildPiStats($piData);
                $piStats['computed_at'] = now()->timestamp;
                Cache::put("dashboard.pi_stats.{$this->userId}", $piStats, 1200);
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            if ($failure instanceof EsiRequestException && $failure->retryable() && $this->job !== null) {
                $this->release(max(1, $failure->retryAfterSeconds) + random_int(1, 5));

                return;
            }
            throw $failure;
        }
    }

    /**
     * Determine whether the cached stats were computed recently enough to
     * skip recomputation.
     */
    protected function isFresh(string $cacheKey): bool
    {
        $cached = Cache::get($cacheKey);

        if (! is_array($cached) || ! isset($cached['computed_at'])) {
            return false;
        }

        return ($cached['computed_at'] + self::FRESH_SECONDS) > now()->timestamp;
    }

    public function onTimeout(): void
    {
        Log::warning("RefreshDashboardStats: Job timed out for user {$this->userId}, will be retried.");
    }

    protected function buildIndustryStats(array $availability): array
    {
        $totals = $availability['totals'] ?? [];

        $inProgress = 0;
        $readyJobs = 0;
        $availableSlots = 0;

        foreach (['manufacturing', 'research', 'reactions'] as $type) {
            $inProgress += $totals[$type]['active'] ?? 0;
            $availableSlots += $totals[$type]['available'] ?? 0;
        }

        foreach ($availability['characters'] ?? [] as $charData) {
            foreach (['manufacturing', 'research', 'reactions'] as $type) {
                foreach ($charData[$type]['jobs'] ?? [] as $job) {
                    if (! empty($job['ready_for_delivery'])) {
                        $readyJobs++;
                    }
                }
            }
        }

        return [
            'in_progress' => $inProgress,
            'ready_jobs' => $readyJobs,
            'available_slots' => $availableSlots,
        ];
    }

    protected function buildPiStats(array $piData): array
    {
        $alerts = $piData['alerts'] ?? [];

        $expiredExtractors = 0;
        $fullStorage = 0;

        foreach ($alerts as $alert) {
            if (($alert['type'] ?? '') === 'extractor_expired') {
                $expiredExtractors++;
            }
        }

        foreach ($piData['characters'] ?? [] as $charData) {
            foreach ($charData['planets'] ?? [] as $planet) {
                foreach ($planet['pins'] ?? [] as $pin) {
                    if (isset($pin['storage']) && ($pin['storage']['usage_percent'] ?? 0) >= 90) {
                        $fullStorage++;
                    }
                }
            }
        }

        return [
            'total_planets' => $piData['summary']['total_planets'] ?? 0,
            'expired_extractors' => $expiredExtractors,
            'full_storage' => $fullStorage,
        ];
    }
}
