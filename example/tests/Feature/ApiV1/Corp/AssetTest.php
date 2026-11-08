<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Jobs\RefreshAssetData;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    public function test_requires_auth(): void
    {
        foreach (['/api/v1/assets', '/api/v1/corporation/assets'] as $url) {
            $this->getJson($url)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_personal_assets_envelope_tree_and_pagination(): void
    {
        $user = $this->makeUser();
        $this->makeDirector($user);
        $this->seedAssetCache($user->id);

        $response = $this->actingAs($user)->getJson('/api/v1/assets?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['item_id', 'type_id', 'type_name', 'name', 'quantity', 'location_id', 'location_name', 'location_flag', 'character_id', 'character_name', 'owner_type', 'is_singleton', 'corporation_name', 'parent_item_id', 'children']],
                'meta' => ['last_updated', 'pagination' => ['page', 'per_page', 'total', 'last_page']],
            ])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('meta.last_updated', date('c', 1792584000))
            ->assertJsonPath('data.*.item_id', [100, 102])
            ->assertJsonPath('data.0.name', 'My Rifter')
            ->assertJsonPath('data.0.children.0.item_id', 101)
            ->assertJsonPath('data.0.children.0.parent_item_id', 100)
            ->assertJsonPath('data.0.children.0.children', [])
            ->assertJsonMissingPath('meta.freshness');

        $this->actingAs($user)->getJson('/api/v1/assets?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.*.item_id', [103]);
    }

    public function test_default_page_size_is_fifty(): void
    {
        $user = $this->makeUser();
        $assets = [];
        for ($i = 1; $i <= 60; $i++) {
            $assets[] = $this->asset(['item_id' => $i]);
        }
        $this->seedAssetCache($user->id, $assets);

        $this->actingAs($user)->getJson('/api/v1/assets')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.pagination.total', 60)
            ->assertJsonPath('meta.pagination.per_page', 50);
    }

    public function test_search_and_sort(): void
    {
        $user = $this->makeUser();
        $this->seedAssetCache($user->id);

        $this->actingAs($user)->getJson('/api/v1/assets?search=pyer')
            ->assertOk()
            ->assertJsonPath('data.*.item_id', [100])
            ->assertJsonPath('data.0.children.*.item_id', [101]);

        $this->actingAs($user)->getJson('/api/v1/assets?sort=quantity&dir=desc')
            ->assertOk()
            ->assertJsonPath('data.*.item_id', [102, 103, 100]);
    }

    public function test_bad_input_is_422(): void
    {
        $user = $this->makeUser();
        $this->seedAssetCache($user->id);

        $this->actingAs($user)->getJson('/api/v1/assets?sort=nope&dir=sideways&per_page=500&page=0')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['sort', 'dir', 'per_page', 'page']);
    }

    public function test_corporation_assets_for_a_director_main(): void
    {
        $user = $this->makeUser();
        $this->makeDirector($user);
        $this->seedAssetCache($user->id);

        $this->actingAs($user)->getJson('/api/v1/corporation/assets')
            ->assertOk()
            ->assertJsonPath('data.*.item_id', [200])
            ->assertJsonPath('data.0.owner_type', 'corporate')
            ->assertJsonPath('data.0.corporation_name', 'Test Corp')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null}>
     */
    public static function corporationAssetDenials(): array
    {
        return [
            'no characters' => [null],
            'no main character' => [['is_main' => false]],
            'main is not a director or CEO' => [['is_director_ceo' => false]],
            'main lacks the corporation assets scope' => [['scopes' => ['esi-assets.read_assets.v1']]],
            'main has no corporation' => [['corporation_id' => 0]],
        ];
    }

    #[DataProvider('corporationAssetDenials')]
    public function test_corporation_assets_forbidden(?array $directorAttrs): void
    {
        $this->mock(EsiService::class)->shouldNotReceive('getCorporationAssets');
        $user = $this->makeUser();
        if ($directorAttrs !== null) {
            $this->makeDirector($user, $directorAttrs);
        }
        // Stale corporate rows in the cache must not leak once access is lost.
        $this->seedAssetCache($user->id);

        $this->actingAs($user)->getJson('/api/v1/corporation/assets')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonMissingPath('data');
    }

    public function test_corporation_assets_403_says_which_check_failed(): void
    {
        $user = $this->makeUser();
        $this->makeDirector($user, ['name' => 'Ana', 'is_director_ceo' => false]);

        foreach (['/api/v1/corporation/assets', '/api/v1/corporation/assets/by-location'] as $url) {
            $this->actingAs($user)->getJson($url)
                ->assertStatus(403)
                ->assertJsonPath('code', 'forbidden')
                ->assertJsonPath('message', 'Your main character (Ana) does not hold the Director or CEO role in their corporation.');
        }

        $other = $this->makeUser();
        $this->actingAs($other)->getJson('/api/v1/corporation/assets/by-location')
            ->assertStatus(403)
            ->assertJsonPath('message', 'No main character is set. Corporation assets can only be retrieved by your main character.');
    }

    public function test_personal_assets_by_location(): void
    {
        $user = $this->makeUser();
        $this->makeDirector($user);
        $this->seedAssetCache($user->id);

        $response = $this->actingAs($user)->getJson('/api/v1/assets/by-location');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['total_items', 'total_quantity', 'system_count', 'location_count', 'type_count'],
                    'solar_systems' => [['solar_system_id', 'solar_system_name', 'stations' => [['location_id', 'location_name', 'items' => [['item_id', 'children']]]]]],
                ],
                'meta' => ['last_updated'],
            ])
            ->assertJsonMissingPath('meta.pagination')
            ->assertJsonPath('meta.last_updated', date('c', 1792584000));

        $items = collect($response->json('data.solar_systems'))
            ->flatMap(fn ($s) => $s['stations'])
            ->flatMap(fn ($st) => $st['items'])
            ->pluck('item_id')->sort()->values()->all();
        $this->assertSame([100, 102, 103], $items, 'top-level personal items only; corporate ones are excluded');

        $this->actingAs($user)->getJson('/api/v1/assets/by-location?search=pyer')
            ->assertOk()
            ->assertJsonPath('data.solar_systems.0.stations.0.items.*.item_id', [100])
            ->assertJsonPath('data.solar_systems.0.stations.0.items.0.children.*.item_id', [101]);

        $this->actingAs($user)->getJson('/api/v1/assets/by-location?search='.str_repeat('x', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    public function test_corporation_assets_by_location_for_a_director_main(): void
    {
        $user = $this->makeUser();
        $this->makeDirector($user);
        $this->seedAssetCache($user->id);

        $this->actingAs($user)->getJson('/api/v1/corporation/assets/by-location')
            ->assertOk()
            ->assertJsonPath('data.summary.location_count', 1)
            ->assertJsonPath('data.solar_systems.0.stations.0.items.0.item_id', 200)
            ->assertJsonPath('data.solar_systems.0.stations.0.items.0.owner_type', 'corporate');
    }

    public function test_refresh_queues_a_forced_job(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $this->seedAssetCache($user->id);

        $this->postJson('/api/v1/assets/refresh')->assertStatus(401);

        $this->actingAs($user)->postJson('/api/v1/assets/refresh')->assertStatus(202)->assertNoContent(202);

        Queue::assertPushed(RefreshAssetData::class, fn ($job) => $job->userId === $user->id && $job->force === true);
        $this->assertNotNull(Cache::get("assets.user.{$user->id}"), 'the list stays until the job replaces it');
    }

    public function test_personal_assets_need_no_corporation_permission(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['is_director_ceo' => false]);
        $this->seedAssetCache($user->id);

        $this->actingAs($user)->getJson('/api/v1/assets')->assertOk()->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_never_exposes_tokens(): void
    {
        $user = $this->makeUser();
        $this->makeDirector($user);
        $this->seedAssetCache($user->id);

        foreach (['/api/v1/assets', '/api/v1/corporation/assets'] as $url) {
            $body = $this->actingAs($user)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('secret-', $body);
            $this->assertStringNotContainsString('token', $body);
        }
    }
}
