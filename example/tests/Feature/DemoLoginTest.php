<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    private function demoUser(): User
    {
        return app(DemoDataService::class)->reset(false);
    }

    public function test_demo_login_is_not_found_unless_enabled(): void
    {
        config(['demo.enabled' => false]);

        $this->post('/demo/login')->assertNotFound();
        $this->get('/login')->assertDontSee('Try the demo');
    }

    public function test_demo_login_signs_in_the_demo_account_with_three_characters(): void
    {
        config(['demo.enabled' => true]);

        $this->get('/login')->assertSee('Try the demo');
        $this->post('/demo/login')->assertRedirect('/app/dashboard');
        $this->assertAuthenticated();

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.demo', true)
            ->assertJsonPath('data.admin', false)
            ->assertJsonCount(3, 'data.characters');
        $this->assertSame(1, User::where('is_demo', true)->count());
    }

    public function test_regular_users_are_not_marked_demo(): void
    {
        $user = User::create(['name' => 'Real', 'email' => 'real@test.com', 'is_active' => true]);

        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.demo', false);
    }

    public function test_demo_account_cannot_change_accounts_devices_or_shared_data(): void
    {
        $user = $this->demoUser();
        $character = $user->characters->first();
        $this->actingAs($user);

        foreach ([
            ['deleteJson', '/api/v1/account'],
            ['deleteJson', "/api/v1/characters/{$character->character_id}"],
            ['postJson', "/api/v1/characters/{$character->character_id}/main"],
            ['putJson', '/api/v1/notifications/subscription'],
            ['postJson', '/api/v1/notifications/test'],
            ['postJson', '/api/v1/moon-scans'],
            ['postJson', '/api/v1/wholemap/signatures'],
            ['postJson', '/api/v1/corporation/build-programs'],
            ['delete', '/account'],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }

        $this->deleteJson('/api/v1/account')->assertJsonPath('code', 'demo_restricted');
        $this->assertSame(3, $user->characters()->count());
    }

    public function test_demo_account_can_still_save_its_own_planning_data(): void
    {
        $this->actingAs($this->demoUser());

        // Reaches validation instead of being rejected as a demo user.
        $this->postJson('/api/v1/station-configs', [])->assertStatus(422);
    }

    public function test_starting_an_eve_login_signs_the_demo_session_out(): void
    {
        $this->actingAs($this->demoUser());

        $this->get('/auth/eve/scopes')->assertOk();
        $this->assertGuest();
    }

    public function test_demo_portraits_are_generated_svg_only_for_demo_ids(): void
    {
        $id = DemoIds::CHARACTER_BASE + 7;

        $this->get("/demo/portrait/{$id}.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get("/demo/logo/{$id}.svg")->assertOk();
        $this->get('/demo/portrait/2119000000.svg')->assertNotFound();
    }

    public function test_reset_command_regenerates_characters_only_when_enabled(): void
    {
        config(['demo.enabled' => false]);
        $this->artisan('demo:reset')->assertSuccessful();
        $this->assertSame(0, User::where('is_demo', true)->count());

        config(['demo.enabled' => true]);
        $this->artisan('demo:reset')->assertSuccessful();
        $first = Character::pluck('character_id')->all();
        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertCount(3, Character::all());
        $this->assertEmpty(array_intersect($first, Character::pluck('character_id')->all()));
    }

    public function test_demo_reset_is_scheduled_daily(): void
    {
        $event = collect(Schedule::events())->first(fn ($e) => str_contains($e->command, 'demo:reset'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
