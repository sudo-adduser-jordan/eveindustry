<?php

namespace App\Observers;

use App\Models\Character;
use Illuminate\Support\Facades\Cache;

/** Drop user projections whenever their ownership or ESI access changes. */
class CharacterObserver
{
    public function saved(Character $character): void
    {
        if ($character->wasChanged(['user_id', 'corporation_id', 'is_main', 'is_esi_active', 'scopes', 'roles', 'is_director_ceo'])) {
            $this->invalidate($character);
        }
    }

    public function deleted(Character $character): void
    {
        $this->invalidate($character);
    }

    private function invalidate(Character $character): void
    {
        foreach (array_unique(array_filter([$character->user_id, $character->getRawOriginal('user_id')])) as $userId) {
            foreach (['assets.user.', 'dashboard.industry_stats.', 'dashboard.pi_stats.'] as $prefix) {
                Cache::forget($prefix.$userId);
            }
            Cache::forget("assets.user.{$userId}.cached_at");
        }
        foreach (['industry.slots.', 'esi.roles.', 'planetary.character.'] as $prefix) {
            Cache::forget($prefix.$character->character_id);
        }
    }
}
