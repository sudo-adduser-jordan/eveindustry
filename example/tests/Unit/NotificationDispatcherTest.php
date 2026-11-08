<?php

namespace Tests\Unit;

use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationDetector;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\PushMessage;
use App\Services\Notifications\PushSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC');
    }

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => 'pilot@test.com', 'is_active' => true]);
    }

    private function subscribe(User $user, string $endpoint = 'https://push.example/a', ?CarbonImmutable $at = null): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => 'key',
            'auth_token' => 'auth',
            'subscribed_at' => $at ?? $this->now->subHour(),
        ]);
    }

    /** @param  list<PushMessage>  $messages */
    private function detector(NotificationType $type, array $messages, ?\Closure $spy = null): NotificationDetector
    {
        return new class($type, $messages, $spy) implements NotificationDetector
        {
            public function __construct(private NotificationType $type, private array $messages, private ?\Closure $spy) {}

            public function type(): NotificationType
            {
                return $this->type;
            }

            public function detect(User $user, array $setting, CarbonImmutable $now, CarbonImmutable $since): array
            {
                if ($this->spy) {
                    ($this->spy)($setting, $now, $since);
                }

                return $this->messages;
            }
        };
    }

    private function sender(string ...$results): PushSender
    {
        return new class($results) implements PushSender
        {
            public array $sent = [];

            public function __construct(private array $results) {}

            public function send(PushSubscription $subscription, array $payload): string
            {
                $this->sent[] = [$subscription->endpoint, $payload['tag']];

                return array_shift($this->results) ?? PushSender::SENT;
            }
        };
    }

    private function message(string $key = 'industry:1:done', NotificationType $type = NotificationType::IndustryJobCompleted): PushMessage
    {
        return new PushMessage($type, $key, 'Industry job finished', 'Body', '/app/industry/jobs');
    }

    private function dispatcher(PushSender $sender, array $detectors): NotificationDispatcher
    {
        return new NotificationDispatcher($sender, new NotificationPreferenceService, $detectors);
    }

    public function test_sends_each_event_once_across_repeated_runs(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        $sender = $this->sender();
        $dispatcher = $this->dispatcher($sender, [$this->detector(NotificationType::IndustryJobCompleted, [$this->message()])]);

        $this->assertSame(1, $dispatcher->dispatchForUser($user, $this->now));
        $this->assertSame(0, $dispatcher->dispatchForUser($user, $this->now->addMinutes(5)));
        $this->assertCount(1, $sender->sent);
    }

    public function test_sends_to_every_device(): void
    {
        $user = $this->user();
        $this->subscribe($user, 'https://push.example/a');
        $this->subscribe($user, 'https://push.example/b');
        $sender = $this->sender();

        $this->dispatcher($sender, [$this->detector(NotificationType::IndustryJobCompleted, [$this->message()])])
            ->dispatchForUser($user, $this->now);

        $this->assertSame(['https://push.example/a', 'https://push.example/b'], array_column($sender->sent, 0));
    }

    public function test_user_without_a_device_is_skipped_entirely(): void
    {
        $user = $this->user();
        $called = false;
        $dispatcher = $this->dispatcher($this->sender(), [
            $this->detector(NotificationType::IndustryJobCompleted, [$this->message()], function () use (&$called) {
                $called = true;
            }),
        ]);

        $this->assertSame(0, $dispatcher->dispatchForUser($user, $this->now));
        $this->assertFalse($called);
    }

    public function test_job_completions_are_on_by_default_and_other_types_off(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        $sender = $this->sender();

        $this->dispatcher($sender, [
            $this->detector(NotificationType::IndustryJobCompleted, [$this->message('industry:1:done')]),
            $this->detector(NotificationType::PiExtractorExpiring, [$this->message('pi:1', NotificationType::PiExtractorExpiring)]),
        ])->dispatchForUser($user, $this->now);

        $this->assertSame([['https://push.example/a', 'industry:1:done']], $sender->sent);
    }

    public function test_a_saved_preference_overrides_the_default(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        NotificationPreference::create(['user_id' => $user->id, 'type' => 'industry.job_completed', 'enabled' => false]);
        NotificationPreference::create(['user_id' => $user->id, 'type' => 'pi.extractor_expiring', 'enabled' => true, 'lead_minutes' => 15]);
        $seen = null;
        $sender = $this->sender();

        $this->dispatcher($sender, [
            $this->detector(NotificationType::IndustryJobCompleted, [$this->message('industry:1:done')]),
            $this->detector(NotificationType::PiExtractorExpiring, [$this->message('pi:1', NotificationType::PiExtractorExpiring)], function ($setting) use (&$seen) {
                $seen = $setting;
            }),
        ])->dispatchForUser($user, $this->now);

        $this->assertSame([['https://push.example/a', 'pi:1']], $sender->sent);
        $this->assertSame(15, $seen['lead_minutes']);
    }

    public function test_corporation_types_need_a_character_in_a_corporation(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        NotificationPreference::create(['user_id' => $user->id, 'type' => 'structure.timer', 'enabled' => true, 'lead_minutes' => 60]);
        $sender = $this->sender();

        $this->dispatcher($sender, [
            $this->detector(NotificationType::StructureTimer, [$this->message('structure:1', NotificationType::StructureTimer)]),
        ])->dispatchForUser($user, $this->now);

        $this->assertSame([], $sender->sent);
    }

    public function test_events_before_the_first_subscription_are_not_announced(): void
    {
        $user = $this->user();
        $this->subscribe($user, at: $this->now->subMinutes(10));
        $since = null;

        $this->dispatcher($this->sender(), [
            $this->detector(NotificationType::IndustryJobCompleted, [], function ($s, $n, $sinceArg) use (&$since) {
                $since = $sinceArg;
            }),
        ])->dispatchForUser($user, $this->now);

        $this->assertTrue($since->equalTo($this->now->subMinutes(10)));
    }

    public function test_lookback_is_capped_for_long_standing_subscriptions(): void
    {
        $user = $this->user();
        $this->subscribe($user, at: $this->now->subDays(30));
        $since = null;

        $this->dispatcher($this->sender(), [
            $this->detector(NotificationType::IndustryJobCompleted, [], function ($s, $n, $sinceArg) use (&$since) {
                $since = $sinceArg;
            }),
        ])->dispatchForUser($user, $this->now);

        $this->assertTrue($since->equalTo($this->now->subHours(NotificationDispatcher::MAX_LOOKBACK_HOURS)));
    }

    public function test_gone_subscriptions_are_removed_and_the_event_still_counts_when_another_device_accepts(): void
    {
        $user = $this->user();
        $this->subscribe($user, 'https://push.example/a');
        $this->subscribe($user, 'https://push.example/b');
        $sender = $this->sender(PushSender::GONE, PushSender::SENT);

        $delivered = $this->dispatcher($sender, [$this->detector(NotificationType::IndustryJobCompleted, [$this->message()])])
            ->dispatchForUser($user, $this->now);

        $this->assertSame(1, $delivered);
        $this->assertSame(['https://push.example/b'], $user->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_an_event_nobody_accepted_is_retried_on_the_next_run(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        $sender = $this->sender(PushSender::FAILED, PushSender::SENT);
        $dispatcher = $this->dispatcher($sender, [$this->detector(NotificationType::IndustryJobCompleted, [$this->message()])]);

        $this->assertSame(0, $dispatcher->dispatchForUser($user, $this->now));
        $this->assertSame(1, $dispatcher->dispatchForUser($user, $this->now->addMinutes(5)));
    }

    public function test_quiet_hours_hold_notifications_until_they_end(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        NotificationSetting::create(['user_id' => $user->id, 'quiet_start' => '11:00', 'quiet_end' => '13:00', 'timezone' => 'UTC']);
        $sender = $this->sender();
        $dispatcher = $this->dispatcher($sender, [$this->detector(NotificationType::IndustryJobCompleted, [$this->message()])]);

        $this->assertSame(0, $dispatcher->dispatchForUser($user, $this->now));
        $this->assertSame(1, $dispatcher->dispatchForUser($user, $this->now->addHour()));
    }

    public function test_a_failing_detector_does_not_block_the_others(): void
    {
        $user = $this->user();
        $this->subscribe($user);
        NotificationPreference::create(['user_id' => $user->id, 'type' => 'pi.extractor_expiring', 'enabled' => true, 'lead_minutes' => 60]);
        $sender = $this->sender();
        $broken = $this->detector(NotificationType::IndustryJobCompleted, [], function () {
            throw new \RuntimeException('ESI down');
        });

        $this->dispatcher($sender, [
            $broken,
            $this->detector(NotificationType::PiExtractorExpiring, [$this->message('pi:1', NotificationType::PiExtractorExpiring)]),
        ])->dispatchForUser($user, $this->now);

        $this->assertSame([['https://push.example/a', 'pi:1']], $sender->sent);
    }

    public function test_send_test_reaches_every_device_and_prunes_gone_ones(): void
    {
        $user = $this->user();
        $this->subscribe($user, 'https://push.example/a');
        $this->subscribe($user, 'https://push.example/b');
        $sender = $this->sender(PushSender::SENT, PushSender::GONE);

        $this->assertSame(1, $this->dispatcher($sender, [])->sendTest($user));
        $this->assertSame(1, $user->pushSubscriptions()->count());
    }
}
