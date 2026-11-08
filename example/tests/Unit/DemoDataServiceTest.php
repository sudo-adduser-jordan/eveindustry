<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\StationConfig;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_creates_three_generated_characters_in_the_reserved_range(): void
    {
        $user = app(DemoDataService::class)->reset(false);

        $this->assertTrue($user->isDemo());
        $this->assertFalse($user->isAdmin());
        $characters = $user->characters;
        $this->assertCount(3, $characters);
        $this->assertCount(1, $characters->where('is_main', true));
        $this->assertCount(3, $characters->pluck('name')->unique());
        foreach ($characters as $character) {
            $this->assertTrue(DemoIds::isDemo($character->character_id));
            $this->assertTrue(DemoIds::isDemo($character->corporation_id));
            $this->assertStringStartsWith('/demo/portrait/', $character->portrait_url);
            $this->assertTrue($character->is_esi_active);
        }
        $this->assertTrue($characters->firstWhere('is_main', true)->is_director_ceo);
    }

    public function test_reset_replaces_characters_and_user_content(): void
    {
        $service = app(DemoDataService::class);
        $user = $service->reset(false);
        $firstIds = $user->characters->pluck('character_id')->all();
        StationConfig::create(['user_id' => $user->id, 'name' => 'Mine', 'structure_type' => 'Raitaru', 'activity_type' => 'manufacturing']);

        $again = $service->reset(false);

        $this->assertSame($user->id, $again->id);
        $this->assertSame(1, User::where('is_demo', true)->count());
        $this->assertCount(3, $again->characters);
        $this->assertEmpty(array_intersect($firstIds, $again->characters->pluck('character_id')->all()));
        $this->assertSame(0, StationConfig::where('user_id', $user->id)->count());
        $this->assertSame(0, Character::whereIn('character_id', $firstIds)->count());
    }

    public function test_real_character_ids_are_never_in_the_demo_range(): void
    {
        $this->assertFalse(DemoIds::isDemo(2_119_000_000));
        $this->assertFalse(DemoIds::isDemo(98_000_001));
        $this->assertTrue(DemoIds::isDemo(DemoIds::CHARACTER_BASE + 1));
        $this->assertTrue(Schema::hasColumn('users', 'is_demo'));
    }
}
