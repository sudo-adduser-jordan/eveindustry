<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchNotifications;
use App\Jobs\DispatchUserNotifications;
use App\Models\Character;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiV1NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'pilot@test.com'): User
    {
        return User::create(['name' => 'Pilot', 'email' => $email, 'is_active' => true]);
    }

    private function subscription(string $endpoint = 'https://push.example/abc'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'authsecret']];
    }

    private function configurePush(): void
    {
        config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/notifications/subscription', $this->subscription())->assertStatus(401);
    }

    public function test_config_lists_every_type_with_job_completions_on_by_default(): void
    {
        $this->configurePush();
        $response = $this->actingAs($this->user())->getJson('/api/v1/notifications')->assertOk();

        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.vapid_public_key', 'public')
            ->assertJsonPath('data.devices', 0);

        $types = collect($response->json('data.types'))->keyBy('key');
        $this->assertTrue($types['industry.job_completed']['enabled']);
        $this->assertNull($types['industry.job_completed']['lead_options']);
        $this->assertFalse($types['pi.extractor_expiring']['enabled']);
        $this->assertSame(60, $types['pi.extractor_expiring']['lead_minutes']);
        $this->assertFalse($types['structure.timer']['available'], 'no corporation character');
    }

    public function test_config_reports_push_as_off_without_vapid_keys(): void
    {
        $this->actingAs($this->user())->getJson('/api/v1/notifications')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.vapid_public_key', null);
    }

    public function test_subscribe_is_idempotent_per_endpoint_and_unsubscribe_removes_it(): void
    {
        $user = $this->user();
        $this->actingAs($user)->putJson('/api/v1/notifications/subscription', $this->subscription())->assertOk()->assertJsonPath('data.devices', 1);
        $first = PushSubscription::first()->subscribed_at;

        $this->travel(5)->minutes();
        $this->putJson('/api/v1/notifications/subscription', $this->subscription())->assertOk()->assertJsonPath('data.devices', 1);
        $this->assertTrue(PushSubscription::first()->subscribed_at->equalTo($first), 'resubscribing keeps the original cutoff');

        $this->putJson('/api/v1/notifications/subscription', $this->subscription('https://push.example/other'))->assertJsonPath('data.devices', 2);
        $this->deleteJson('/api/v1/notifications/subscription', ['endpoint' => 'https://push.example/abc'])->assertOk()->assertJsonPath('data.devices', 1);
    }

    public function test_a_second_user_signing_in_on_the_same_browser_takes_over_the_endpoint(): void
    {
        $a = $this->user('a@test.com');
        $b = $this->user('b@test.com');
        $this->actingAs($a)->putJson('/api/v1/notifications/subscription', $this->subscription())->assertOk();
        $this->actingAs($b)->putJson('/api/v1/notifications/subscription', $this->subscription())->assertOk();

        $this->assertSame($b->id, PushSubscription::sole()->user_id);
    }

    public function test_subscribe_validates_the_payload(): void
    {
        $this->actingAs($this->user())->putJson('/api/v1/notifications/subscription', ['endpoint' => 'http://insecure.example/x', 'keys' => []])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_preferences_update_merges_and_persists(): void
    {
        $user = $this->user();
        Character::create(['character_id' => 9001, 'user_id' => $user->id, 'name' => 'Alpha', 'corporation_id' => 0, 'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(), 'is_esi_active' => true, 'scopes' => []]);

        $response = $this->actingAs($user)->putJson('/api/v1/notifications', [
            'types' => [
                'industry.job_completed' => ['enabled' => false],
                'pi.extractor_expiring' => ['enabled' => true, 'lead_minutes' => 360, 'character_ids' => [9001]],
            ],
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/London'],
        ])->assertOk();

        $types = collect($response->json('data.types'))->keyBy('key');
        $this->assertFalse($types['industry.job_completed']['enabled']);
        $this->assertTrue($types['pi.extractor_expiring']['enabled']);
        $this->assertSame(360, $types['pi.extractor_expiring']['lead_minutes']);
        $this->assertSame([9001], $types['pi.extractor_expiring']['character_ids']);
        $response->assertJsonPath('data.quiet_hours', ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/London']);

        // A later partial change leaves the rest alone.
        $again = $this->putJson('/api/v1/notifications', ['types' => ['pi.extractor_expiring' => ['lead_minutes' => 15]]])->assertOk();
        $pi = collect($again->json('data.types'))->keyBy('key')['pi.extractor_expiring'];
        $this->assertTrue($pi['enabled']);
        $this->assertSame(15, $pi['lead_minutes']);
        $this->assertSame([9001], $pi['character_ids']);

        $cleared = $this->putJson('/api/v1/notifications', ['quiet_hours' => ['start' => null, 'end' => null]])->assertOk();
        $cleared->assertJsonPath('data.quiet_hours.start', null);
    }

    public function test_preferences_reject_unknown_types_bad_lead_times_and_foreign_characters(): void
    {
        $user = $this->user();
        $other = $this->user('other@test.com');
        Character::create(['character_id' => 7001, 'user_id' => $other->id, 'name' => 'Stranger', 'corporation_id' => 0, 'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(), 'is_esi_active' => true, 'scopes' => []]);

        $this->actingAs($user)->putJson('/api/v1/notifications', ['types' => ['nope.nothing' => ['enabled' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors(['types.nope.nothing']);
        $this->putJson('/api/v1/notifications', ['types' => ['pi.extractor_expiring' => ['lead_minutes' => 7]]])
            ->assertStatus(422);
        $this->putJson('/api/v1/notifications', ['types' => ['industry.job_completed' => ['lead_minutes' => 60]]])
            ->assertStatus(422);
        $this->putJson('/api/v1/notifications', ['types' => ['pi.extractor_expiring' => ['character_ids' => [7001]]]])
            ->assertStatus(422);
        $this->putJson('/api/v1/notifications', ['quiet_hours' => ['start' => '25:99', 'end' => '07:00']])
            ->assertStatus(422);
    }

    public function test_test_endpoint_is_unavailable_without_vapid_keys(): void
    {
        $this->actingAs($this->user())->postJson('/api/v1/notifications/test')->assertStatus(503);
    }

    public function test_test_endpoint_sends_to_every_device_when_configured(): void
    {
        $this->actingAs($this->user());
        $this->configurePush();
        $this->app->instance(PushSender::class, new class implements PushSender
        {
            public function send(PushSubscription $subscription, array $payload): string
            {
                return PushSender::SENT;
            }
        });
        $this->putJson('/api/v1/notifications/subscription', $this->subscription())->assertOk();

        $this->postJson('/api/v1/notifications/test')->assertOk()->assertJsonPath('data.sent', 1);
    }

    public function test_dispatch_command_queues_only_users_with_a_device_and_prunes_the_log(): void
    {
        Queue::fake();
        $this->configurePush();
        $withDevice = $this->user('a@test.com');
        $this->user('b@test.com');
        PushSubscription::create(['user_id' => $withDevice->id, 'endpoint' => 'https://push.example/a', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/a'), 'public_key' => 'k', 'auth_token' => 'a', 'subscribed_at' => now()]);
        DB::table('notification_log')->insert([
            ['user_id' => $withDevice->id, 'type' => 'industry.job_completed', 'dedupe_key' => 'old', 'sent_at' => now()->subDays(20)],
            ['user_id' => $withDevice->id, 'type' => 'industry.job_completed', 'dedupe_key' => 'new', 'sent_at' => now()->subDay()],
        ]);

        $this->artisan('notifications:dispatch')->assertSuccessful();

        Queue::assertPushed(DispatchUserNotifications::class, 1);
        $this->assertSame(['new'], DB::table('notification_log')->pluck('dedupe_key')->all());
        $this->assertTrue(class_exists(DispatchNotifications::class));
    }

    public function test_dispatch_command_queues_nothing_when_push_is_not_configured(): void
    {
        Queue::fake();
        $user = $this->user();
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://push.example/a', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/a'), 'public_key' => 'k', 'auth_token' => 'a', 'subscribed_at' => now()]);

        $this->artisan('notifications:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
