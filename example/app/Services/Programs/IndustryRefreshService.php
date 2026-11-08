<?php

namespace App\Services\Programs;

use App\Models\User;
use App\Services\IndustryService;
use App\Services\ProductionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The cache refresh / clear triggers of the industry pages.
 *
 * Shared by the old /api/industry/... routes and the /api/v1/industry/...
 * ones. Nothing here dispatches a job: the data is recomputed inline.
 */
class IndustryRefreshService
{
    /** Minutes a user must wait between two full cache clears. */
    public const CLEAR_THROTTLE_MINUTES = 30;

    public function __construct(
        protected IndustryService $industryService,
        protected ProductionService $productionService,
    ) {}

    /**
     * Forget the user's slot caches and overview, then recompute the
     * availability inline (which warms the cache again).
     *
     * @return array<string, mixed> the availability data
     */
    public function refreshAvailability(int $userId): array
    {
        $this->industryService->clearUserCache($userId);

        return $this->industryService->getAvailabilityForUser($userId);
    }

    /**
     * Clear every industry and ESI cache of the user's characters, at most
     * once every 30 minutes.
     *
     * @return string|null null when the caches were cleared; otherwise the
     *                     "last cleared ... please wait" message, and nothing was cleared
     */
    public function clearAllCaches(int $userId): ?string
    {
        $cacheKey = "industry.clear_last:{$userId}";

        $last = Cache::get($cacheKey);
        if ($last) {
            try {
                $lastTime = \Carbon\Carbon::parse($last);
            } catch (\Throwable $e) {
                $lastTime = null;
            }

            if ($lastTime && $lastTime->gt(now()->subMinutes(self::CLEAR_THROTTLE_MINUTES))) {
                $diffInMinutes = round(now()->diffInMinutes($lastTime), 0);
                $minutesLeft = self::CLEAR_THROTTLE_MINUTES + $diffInMinutes;
                $waitText = $minutesLeft > 0 ? "{$minutesLeft} minute(s)" : 'less than a minute';
                $when = $lastTime->toDayDateTimeString();

                return "Caches were last cleared at {$when}. Please wait {$waitText} before clearing again.";
            }
        }

        $this->industryService->clearAllCachesForUser($userId);

        $now = now();
        Cache::put($cacheKey, $now->toIso8601String(), $now->addMinutes(self::CLEAR_THROTTLE_MINUTES));

        Log::info('Industry caches cleared', ['user_id' => $userId, 'cleared_at' => $now->toIso8601String()]);

        return null;
    }

    /**
     * Forget the can-produce result and the ESI blueprint/asset/role caches
     * of the user's characters (and their corporations), recompute the
     * result inline and cache it for an hour.
     *
     * @return array<int, array<string, mixed>> the can-produce sources
     */
    public function refreshCanProduce(User $user): array
    {
        $userId = $user->id;

        Cache::forget("can_produce.{$userId}");

        foreach ($user->characters as $character) {
            Cache::forget("esi.blueprints.{$character->character_id}");
            Cache::forget("esi.assets.{$character->character_id}");
            Cache::forget("esi.roles.{$character->character_id}");
            if ($character->corporation_id) {
                Cache::forget("esi.corp_blueprints.{$character->corporation_id}");
                Cache::forget("esi.corp_assets.{$character->corporation_id}");
            }
        }

        $sources = $this->productionService->getCanProduceForUser($userId);
        Cache::put("can_produce.{$userId}", $sources, 3600);

        return $sources;
    }
}
