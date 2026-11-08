<?php

namespace Tests\Feature\LegacyApi\Corp;

use App\Models\Character;
use App\Models\CorporateDonationConfig;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Shared fixtures for the corp group's legacy and v1 feature tests.
 */
trait CorpFixtures
{
    protected function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Pilot',
            'email' => 'p'.uniqid().'@test.com',
            'is_active' => true,
        ], $attrs));
    }

    protected function makeCharacter(User $user, array $attrs = []): Character
    {
        return Character::create(array_merge([
            'character_id' => random_int(90000000, 99999999),
            'user_id' => $user->id,
            'name' => 'Main Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => [],
        ], $attrs));
    }

    /**
     * A director main with the corporation assets scope: the only kind of
     * character AssetService fetches corporation assets for.
     */
    protected function makeDirector(User $user, array $attrs = []): Character
    {
        return $this->makeCharacter($user, array_merge([
            'is_director_ceo' => true,
            'scopes' => ['esi-assets.read_assets.v1', 'esi-assets.read_corporation_assets.v1'],
        ], $attrs));
    }

    /**
     * Seed the forever-cache that RefreshAssetData fills, so no ESI call happens.
     */
    protected function seedAssetCache(int $userId, ?array $assets = null, int $cachedAt = 1792584000): array
    {
        config(['assets.durable_snapshots' => false]);
        $assets ??= $this->assetFixture();
        Cache::forever("assets.user.{$userId}", $assets);
        Cache::forever("assets.user.{$userId}.cached_at", $cachedAt);

        return $assets;
    }

    protected function asset(array $attrs): array
    {
        return array_merge([
            'item_id' => 1,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'name' => null,
            'quantity' => 1,
            'location_id' => 60003760,
            'location_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'location_flag' => 'Hangar',
            'character_id' => 9001,
            'character_name' => 'Main Pilot',
            'owner_type' => 'personal',
            'is_singleton' => false,
            'corporation_name' => null,
            'parent_item_id' => null,
        ], $attrs);
    }

    /**
     * Personal: a ship holding Pyerite, loose Tritanium, a Zydrine stack.
     * Corporate: Mexallon in a corp hangar.
     */
    protected function assetFixture(): array
    {
        return [
            $this->asset(['item_id' => 100, 'type_id' => 587, 'type_name' => 'Rifter', 'name' => 'My Rifter', 'is_singleton' => true]),
            $this->asset(['item_id' => 101, 'type_id' => 35, 'type_name' => 'Pyerite', 'quantity' => 500, 'location_id' => 100, 'location_name' => 'Rifter', 'location_flag' => 'Cargo', 'parent_item_id' => 100]),
            $this->asset(['item_id' => 102, 'type_id' => 34, 'type_name' => 'Tritanium', 'quantity' => 1000]),
            $this->asset(['item_id' => 103, 'type_id' => 39, 'type_name' => 'Zydrine', 'quantity' => 7, 'location_name' => 'Amarr VIII (Oris) - Emperor Family Academy']),
            $this->asset(['item_id' => 200, 'type_id' => 36, 'type_name' => 'Mexallon', 'quantity' => 2500, 'location_flag' => 'Corp Hangar 1', 'owner_type' => 'corporate', 'corporation_name' => 'Test Corp']),
        ];
    }

    protected function enableDonations(Character $provider, int $enabledBy): CorporateDonationConfig
    {
        return CorporateDonationConfig::create([
            'corporation_id' => $provider->corporation_id,
            'corporation_name' => $provider->corporation_name,
            'character_id' => $provider->character_id,
            'enabled_by_user_id' => $enabledBy,
            'is_active' => true,
        ]);
    }

    protected function journalEntry(int $id, int $donator, float $amount, string $date, string $reason = ''): array
    {
        return [
            'id' => $id,
            'ref_type' => 'player_donation',
            'amount' => $amount,
            'date' => $date,
            'first_party_id' => $donator,
            'reason' => $reason,
            'balance' => 1000000000,
        ];
    }

    protected function project(string $id, int $current, int $desired, string $lastModified, ?array $reward = null, string $state = 'Active'): array
    {
        $project = [
            'id' => $id,
            'name' => "Project {$id}",
            'state' => $state,
            'last_modified' => $lastModified,
            'progress' => ['current' => $current, 'desired' => $desired],
        ];
        if ($reward !== null) {
            $project['reward'] = $reward;
        }

        return $project;
    }
}
