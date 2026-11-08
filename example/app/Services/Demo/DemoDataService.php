<?php

namespace App\Services\Demo;

use App\Jobs\RefreshAssetData;
use App\Jobs\RefreshDashboardStats;
use App\Jobs\RefreshIndustryData;
use App\Models\AssetSnapshotOwner;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the shared demo account. reset() throws away everything the account
 * holds, generates three new characters (new names, ids and corporations) and
 * rebuilds the cached datasets from the locally generated ESI payloads.
 */
class DemoDataService
{
    public function reset(bool $warm = true): User
    {
        $user = DB::transaction(function () {
            $user = User::firstOrNew(['email' => config('demo.email')]);
            $user->fill([
                'name' => 'Demo Account',
                'is_admin' => false,
                'is_super_admin' => false,
                'is_active' => true,
                'is_stock_manager_enabled' => true,
                'is_demo' => true,
                'last_login_at' => now(),
            ])->save();

            $this->wipe($user);
            $this->createCharacters($user);

            return $user;
        });

        if ($warm) {
            $this->warm($user);
        }

        return $user->refresh();
    }

    /** Remove the previous characters and everything the demo user created. */
    private function wipe(User $user): void
    {
        $characters = $user->characters()->get(['character_id', 'corporation_id']);

        if ($characters->isNotEmpty()) {
            AssetSnapshotOwner::query()->where(function ($q) use ($characters) {
                $q->where(fn ($q) => $q->where('owner_type', 'personal')->whereIn('owner_id', $characters->pluck('character_id')))
                    ->orWhere(fn ($q) => $q->where('owner_type', 'corporate')->whereIn('owner_id', $characters->pluck('corporation_id')->unique()));
            })->delete();
        }

        $user->stationConfigs()->delete();
        $user->reactionPlans()->delete();
        $user->inventionPlans()->delete();
        $user->buildPrograms()->delete();
        $user->industryStockItems()->delete();
        $user->pushSubscriptions()->delete();
        DB::table('notification_preferences')->where('user_id', $user->id)->delete();
        DB::table('notification_settings')->where('user_id', $user->id)->delete();
        DB::table('notification_log')->where('user_id', $user->id)->delete();
        $user->characters()->delete();

        foreach (["assets.user.{$user->id}", "assets.user.{$user->id}.cached_at", "industry.overview.{$user->id}", "dashboard.industry_stats.{$user->id}", "dashboard.pi_stats.{$user->id}"] as $key) {
            Cache::forget($key);
        }
    }

    private function createCharacters(User $user): void
    {
        $count = (int) config('demo.character_count', 3);
        $characterId = $this->nextId(DemoIds::CHARACTER_BASE, DemoIds::CORPORATION_BASE, 'characters', 'character_id');
        $corpId = $this->nextId(DemoIds::CORPORATION_BASE, DemoIds::ALLIANCE_BASE, 'characters', 'corporation_id');
        $allianceId = $this->nextId(DemoIds::ALLIANCE_BASE, DemoIds::PLANET_BASE, 'characters', 'alliance_id');

        $mainCorp = ['id' => $corpId, 'name' => DemoNames::corporation()];
        $otherCorp = ['id' => $corpId + 1, 'name' => DemoNames::corporation()];
        $alliance = ['id' => $allianceId, 'name' => DemoNames::alliance()];

        $usedNames = [];
        for ($i = 0; $i < $count; $i++) {
            do {
                $name = DemoNames::pilot($i);
            } while (isset($usedNames[$name]) || Character::where('name', $name)->exists());
            $usedNames[$name] = true;

            // The first character is the main and runs the corporation; the
            // second shares it as a member; the third belongs to another corp.
            $corp = $i === 2 ? $otherCorp : $mainCorp;
            $director = $i === 0;
            $characterId += random_int(1, 40);

            Character::create([
                'character_id' => $characterId,
                'user_id' => $user->id,
                'name' => $name,
                'corporation_id' => $corp['id'],
                'corporation_name' => $corp['name'],
                'alliance_id' => $i === 2 ? null : $alliance['id'],
                'alliance_name' => $i === 2 ? null : $alliance['name'],
                'is_main' => $i === 0,
                'access_token' => 'demo-access-token',
                'refresh_token' => 'demo-refresh-token',
                'token_expires_at' => now()->addYears(10),
                'scopes' => DemoEsiResponder::SCOPES,
                'is_esi_active' => true,
                'is_director_ceo' => $director,
                'roles' => $director ? DemoEsiResponder::CORP_ROLES : [],
            ]);
        }

        // Location and training are normally filled in by characters:refresh-data.
        $responder = app(DemoEsiResponder::class);
        foreach ($user->characters()->get() as $character) {
            $character->update([
                'cached_location' => $responder->home($character)['system'],
                'cached_training' => 'Advanced Mass Reactions 4',
            ]);
        }
    }

    private function nextId(int $floor, int $ceiling, string $table, string $column): int
    {
        $max = DB::table($table)->where($column, '>=', $floor)->where($column, '<', $ceiling)->max($column);

        // A random step keeps ids from repeating across resets, so caches keyed by an old id never apply to a new character.
        return max($floor, (int) $max) + random_int(1_000, 40_000_000);
    }

    /** Fill the dashboard, industry and asset caches so the first page load is not empty. */
    public function warm(User $user): void
    {
        foreach ([fn () => RefreshAssetData::dispatchSync($user->id, true), fn () => RefreshIndustryData::dispatchSync($user->id), fn () => RefreshDashboardStats::dispatchSync($user->id)] as $job) {
            try {
                $job();
            } catch (\Throwable $e) {
                Log::warning('Demo warm-up step failed: '.$e->getMessage());
            }
        }
    }
}
