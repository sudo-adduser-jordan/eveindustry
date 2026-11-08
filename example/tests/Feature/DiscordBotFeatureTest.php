<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Discord welcome bot is a work in progress, hidden unless config/features.php turns it on.
 */
class DiscordBotFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password123', 'is_admin' => true, 'is_active' => true]);
    }

    public function test_everything_is_hidden_while_the_flag_is_off(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/admin/discord-bot')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($admin)->putJson('/api/v1/admin/discord-bot', ['bot_name' => 'Bot'])->assertNotFound();
        $this->actingAs($admin)->get('/admin/discord-bot')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.features.discord_bot', false);
        $this->assertDatabaseCount('discord_bot_settings', 0);
    }

    public function test_the_command_exits_while_the_flag_is_off(): void
    {
        $this->artisan('discord:bot')
            ->expectsOutputToContain('work in progress')
            ->assertFailed();

        $this->assertDatabaseCount('discord_bot_settings', 0);
    }

    public function test_everything_is_available_with_the_flag_on(): void
    {
        config()->set('features.discord_bot', true);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/admin/discord-bot')->assertOk()->assertJsonPath('data.has_bot_token', false);
        $this->actingAs($admin)->get('/admin/discord-bot')->assertRedirect('/app/admin/discord-bot');
        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.features.discord_bot', true);

        // Past the flag, the command reaches its own settings checks.
        $this->artisan('discord:bot')
            ->expectsOutputToContain('disabled')
            ->assertFailed();
    }
}
