<?php

namespace Tests\Feature\ApiV1\Admin;

use App\Models\DiscordBotSetting;
use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sponsors, the admin account and the Discord bot.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password123', 'is_admin' => true, 'is_active' => true]);
    }

    // Sponsors

    public function test_sponsor_lifecycle(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $id = $this->actingAs($admin)->post('/api/v1/admin/sponsors', [
            'name' => 'New Sponsor',
            'url' => 'https://newsponsor.com',
            'image' => UploadedFile::fake()->image('banner.png', 300, 250),
            'is_active' => '1',
            'sort_order' => 5,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Sponsor')
            ->assertJsonPath('data.sort_order', 5)
            ->json('data.id');

        $oldPath = Sponsor::findOrFail($id)->image_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($admin)->post("/api/v1/admin/sponsors/{$id}", [
            'name' => 'Renamed',
            'url' => 'https://renamed.com',
            'is_active' => '0',
            'sort_order' => 2,
        ], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('sponsors', ['id' => $id, 'name' => 'Renamed', 'is_active' => false, 'image_path' => $oldPath]);

        $this->actingAs($admin)->post("/api/v1/admin/sponsors/{$id}", [
            'name' => 'Renamed',
            'url' => 'https://renamed.com',
            'image' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($oldPath);

        $this->actingAs($admin)->getJson('/api/v1/admin/sponsors')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson("/api/v1/admin/sponsors/{$id}")->assertJsonPath('data.name', 'Renamed');

        $this->actingAs($admin)->deleteJson("/api/v1/admin/sponsors/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('sponsors', ['id' => $id]);
    }

    public function test_sponsor_banners_go_to_the_configured_uploads_disk(): void
    {
        config(['filesystems.uploads' => 's3']);
        Storage::fake('public');
        Storage::fake('s3');
        $admin = $this->admin();
        // A 1x1 PNG, so the test does not need GD.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $response = $this->actingAs($admin)->post('/api/v1/admin/sponsors', [
            'name' => 'Bucket Sponsor',
            'url' => 'https://bucket.example',
            'image' => UploadedFile::fake()->createWithContent('banner.png', $png),
        ], ['Accept' => 'application/json'])->assertCreated();

        $sponsor = Sponsor::findOrFail($response->json('data.id'));
        Storage::disk('s3')->assertExists($sponsor->image_path);
        Storage::disk('public')->assertMissing($sponsor->image_path);
        $response->assertJsonPath('data.image_url', Storage::disk('s3')->url($sponsor->image_path));

        $this->actingAs($admin)->deleteJson("/api/v1/admin/sponsors/{$sponsor->id}")->assertNoContent();
        Storage::disk('s3')->assertMissing($sponsor->image_path);
    }

    public function test_sponsor_banner_urls_default_to_the_public_disk(): void
    {
        $sponsor = new Sponsor(['image_path' => 'sponsors/a.png']);

        $this->assertSame(rtrim(config('app.url'), '/').'/storage/sponsors/a.png', $sponsor->imageUrl());
    }

    public function test_sponsor_store_validates(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/sponsors', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'url', 'image']);
    }

    // Account

    public function test_account_email_and_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/admin/account')->assertJsonPath('data.email', 'admin@example.com');

        $this->actingAs($admin)->putJson('/api/v1/admin/account', ['email' => 'new@example.com'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.com');

        $this->actingAs($admin)->putJson('/api/v1/admin/account', [
            'email' => 'new@example.com',
            'current_password' => 'password123',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ])->assertOk();
        $this->assertTrue(Hash::check('newpassword456', $admin->fresh()->password));
    }

    public function test_account_password_needs_the_current_one_and_a_confirmation(): void
    {
        $admin = $this->admin();
        $new = ['password' => 'newpassword456', 'password_confirmation' => 'newpassword456'];

        $this->actingAs($admin)->putJson('/api/v1/admin/account', ['email' => $admin->email] + $new)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->actingAs($admin)->putJson('/api/v1/admin/account', ['email' => $admin->email, 'current_password' => 'wrong'] + $new)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->actingAs($admin)->putJson('/api/v1/admin/account', ['email' => $admin->email, 'current_password' => 'password123', 'password' => 'newpassword456', 'password_confirmation' => 'other'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($admin)->putJson('/api/v1/admin/account', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        User::create(['name' => 'Other', 'email' => 'other@example.com', 'is_active' => true]);
        $this->actingAs($admin)->putJson('/api/v1/admin/account', ['email' => 'other@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('password123', $admin->fresh()->password));
    }

    // Discord bot

    private function bot(array $overrides = []): array
    {
        return array_merge([
            'bot_name' => 'Welcomer Bot',
            'bot_avatar_url' => 'https://example.com/avatar.png',
            'welcome_channel_id' => '123456789012345678',
            'welcome_message' => 'Welcome, {user}!',
            'is_enabled' => true,
        ], $overrides);
    }

    public function test_discord_bot_never_returns_the_token_and_keeps_it_when_left_out(): void
    {
        config()->set('features.discord_bot', true);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson('/api/v1/admin/discord-bot', $this->bot(['bot_token' => 'secret-token']))
            ->assertOk()
            ->assertJsonPath('data.has_bot_token', true)
            ->assertJsonPath('data.bot_name', 'Welcomer Bot')
            ->assertJsonMissingPath('data.bot_token');
        $this->assertSame('secret-token', DiscordBotSetting::getSettings()->bot_token);

        $this->actingAs($admin)->putJson('/api/v1/admin/discord-bot', $this->bot(['bot_token' => '', 'is_enabled' => false]))->assertOk();
        $settings = DiscordBotSetting::getSettings();
        $this->assertSame('secret-token', $settings->bot_token);
        $this->assertFalse($settings->is_enabled);

        $this->actingAs($admin)->getJson('/api/v1/admin/discord-bot')->assertJsonPath('data.has_bot_token', true)->assertJsonMissingPath('data.bot_token');

        $this->actingAs($admin)->putJson('/api/v1/admin/discord-bot', $this->bot(['clear_bot_token' => true]))
            ->assertOk()->assertJsonPath('data.has_bot_token', false);
        $this->assertNull(DiscordBotSetting::getSettings()->bot_token);
    }

    public function test_discord_bot_validation(): void
    {
        config()->set('features.discord_bot', true);
        $admin = $this->admin();

        foreach ([
            ['bot_name' => ''],
            ['welcome_message' => ''],
            ['welcome_message' => str_repeat('a', 2001)],
            ['bot_avatar_url' => 'not-a-url'],
            ['welcome_channel_id' => 'not-a-channel-id'],
        ] as $bad) {
            $this->actingAs($admin)->putJson('/api/v1/admin/discord-bot', $this->bot($bad))
                ->assertStatus(422)->assertJsonValidationErrors(array_keys($bad));
        }
    }

    public function test_settings_row_is_created_on_first_access(): void
    {
        $this->assertDatabaseCount('discord_bot_settings', 0);
        DiscordBotSetting::getSettings();
        $this->assertDatabaseCount('discord_bot_settings', 1);
    }
}
