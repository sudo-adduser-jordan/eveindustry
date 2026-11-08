<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationDispatcher
{
    /** How far back a missed event is still announced (scheduler or queue outage). */
    public const MAX_LOOKBACK_HOURS = 6;

    /** @param  iterable<NotificationDetector>  $detectors */
    public function __construct(
        private PushSender $sender,
        private NotificationPreferenceService $preferences,
        private iterable $detectors,
    ) {}

    /** @return int Notifications delivered to at least one device. */
    public function dispatchForUser(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty() || $this->preferences->isQuiet($user, $now)) {
            return 0;
        }

        // Never announce what happened before the first device subscribed.
        $since = max(
            CarbonImmutable::instance($subscriptions->min('subscribed_at')),
            $now->subHours(self::MAX_LOOKBACK_HOURS),
        );
        $settings = $this->preferences->effective($user);
        $delivered = 0;

        foreach ($this->detectors as $detector) {
            $type = $detector->type();
            if (! $settings[$type->value]['enabled'] || ! $this->preferences->isAvailable($user, $type)) {
                continue;
            }

            try {
                $messages = $detector->detect($user, $settings[$type->value], $now, $since);
            } catch (\Throwable $e) {
                // One failing data source must not silence the other types.
                Log::warning('Notification detector failed', ['type' => $type->value, 'user' => $user->id, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($messages as $message) {
                if ($this->deliver($user, $subscriptions, $message, $now)) {
                    $delivered++;
                }
            }
        }

        return $delivered;
    }

    /** Sends a test message to every device, bypassing preferences and de-duplication. */
    public function sendTest(User $user): int
    {
        $sent = 0;
        $message = new PushMessage(
            NotificationType::IndustryJobCompleted,
            'test:'.CarbonImmutable::now('UTC')->getTimestamp(),
            'Notifications are working',
            'This is a test notification from Eve Industrialist.',
            '/app/dashboard',
        );

        foreach ($user->pushSubscriptions()->get() as $subscription) {
            $result = $this->sender->send($subscription, $message->payload());
            if ($result === PushSender::GONE) {
                $subscription->delete();
            } elseif ($result === PushSender::SENT) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @param  \Illuminate\Support\Collection<int, PushSubscription>  $subscriptions */
    private function deliver(User $user, $subscriptions, PushMessage $message, CarbonImmutable $now): bool
    {
        // Claim the event first so overlapping runs cannot both send it.
        $claimed = DB::table('notification_log')->insertOrIgnore([
            'user_id' => $user->id,
            'type' => $message->type->value,
            'dedupe_key' => $message->dedupeKey,
            'sent_at' => $now,
        ]);

        if ($claimed === 0) {
            return false;
        }

        $accepted = false;
        foreach ($subscriptions as $subscription) {
            $result = $this->sender->send($subscription, $message->payload());

            if ($result === PushSender::SENT) {
                $accepted = true;
                $subscription->forceFill(['last_used_at' => $now])->save();
            } elseif ($result === PushSender::GONE) {
                $subscription->delete();
            }
        }

        // Nothing reached a device: release the claim so the next run retries.
        if (! $accepted) {
            DB::table('notification_log')->where('user_id', $user->id)->where('dedupe_key', $message->dedupeKey)->delete();
        }

        return $accepted;
    }
}
