<?php

namespace Tests\Feature\ApiV1\Programs;

use App\Services\IndustryService;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

class RefreshTriggersTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    public function test_requires_auth(): void
    {
        foreach (['/industry/availability/clear', '/industry/availability/refresh', '/industry/can-produce/refresh'] as $path) {
            $this->postJson('/api/v1'.$path)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_availability_refresh_recomputes_inline_and_returns_204(): void
    {
        Queue::fake();
        Bus::fake();
        $user = $this->makeUser();

        $this->mock(IndustryService::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('clearUserCache')->once()->with($user->id)->ordered();
            $mock->shouldReceive('getAvailabilityForUser')->once()->with($user->id)->ordered()->andReturn(['characters' => [], 'totals' => []]);
        });

        $response = $this->actingAs($user)->postJson('/api/v1/industry/availability/refresh');

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_clear_forgets_the_caches_and_returns_204(): void
    {
        Queue::fake();
        Bus::fake();
        $user = $this->makeUser();
        $other = $this->makeUser();
        $char = $this->makeCharacter($user, ['character_id' => 91000001, 'corporation_id' => 98000001]);
        $otherChar = $this->makeCharacter($other, ['character_id' => 91000002, 'corporation_id' => 98000009]);

        $keys = [
            "industry.slots.{$char->character_id}", "industry.overview.{$user->id}",
            "esi.character.{$char->character_id}", "esi.skills.{$char->character_id}",
            "esi.industry_jobs.{$char->character_id}.active", "esi.industry_jobs.{$char->character_id}.with_completed",
            "esi.blueprints.{$char->character_id}", "esi.assets.{$char->character_id}", "esi.roles.{$char->character_id}",
            'esi.corp_industry_jobs.98000001.active', 'esi.corp_industry_jobs.98000001.with_completed',
        ];
        foreach ($keys as $key) {
            Cache::put($key, 'x', 600);
        }
        Cache::put("industry.slots.{$otherChar->character_id}", 'keep', 600);

        $this->actingAs($user)->postJson('/api/v1/industry/availability/clear')->assertNoContent();

        foreach ($keys as $key) {
            $this->assertFalse(Cache::has($key), "{$key} should be cleared");
        }
        $this->assertSame('keep', Cache::get("industry.slots.{$otherChar->character_id}"));
        $this->assertTrue(Cache::has("industry.clear_last:{$user->id}"));
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_clear_is_throttled_to_once_every_thirty_minutes_with_429(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/industry/availability/clear')->assertNoContent();

        Cache::put("industry.overview.{$user->id}", 'keep-me', 600);
        $this->actingAs($user)->postJson('/api/v1/industry/availability/clear')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
        $this->assertSame('keep-me', Cache::get("industry.overview.{$user->id}"));

        Cache::put("industry.clear_last:{$user->id}", now()->subMinutes(31)->toIso8601String(), 600);
        $this->actingAs($user)->postJson('/api/v1/industry/availability/clear')->assertNoContent();
        $this->assertFalse(Cache::has("industry.overview.{$user->id}"));
    }

    public function test_can_produce_refresh_clears_caches_stores_the_result_and_returns_204(): void
    {
        Queue::fake();
        Bus::fake();
        $user = $this->makeUser();
        $char = $this->makeCharacter($user, ['character_id' => 91000001, 'corporation_id' => 98000001]);
        $otherChar = $this->makeCharacter($this->makeUser(), ['character_id' => 91000002, 'corporation_id' => 98000009]);

        $stale = [
            "can_produce.{$user->id}", "esi.blueprints.{$char->character_id}", "esi.assets.{$char->character_id}",
            "esi.roles.{$char->character_id}", 'esi.corp_blueprints.98000001', 'esi.corp_assets.98000001',
        ];
        foreach ($stale as $key) {
            Cache::put($key, 'stale', 600);
        }
        Cache::put("esi.blueprints.{$otherChar->character_id}", 'keep', 600);

        $sources = [['id' => 1]];
        $this->mock(ProductionService::class, function (MockInterface $mock) use ($user, $sources) {
            $mock->shouldReceive('getCanProduceForUser')->once()->with($user->id)->andReturn($sources);
        });

        $this->actingAs($user)->postJson('/api/v1/industry/can-produce/refresh')->assertNoContent();

        foreach (array_slice($stale, 1) as $key) {
            $this->assertFalse(Cache::has($key), "{$key} should be cleared");
        }
        $this->assertSame($sources, Cache::get("can_produce.{$user->id}"));
        $this->assertSame('keep', Cache::get("esi.blueprints.{$otherChar->character_id}"));
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }
}
