<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushSender implements PushSender
{
    private ?WebPush $client = null;

    public static function configured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    public function send(PushSubscription $subscription, array $payload): string
    {
        if (! self::configured()) {
            return self::FAILED;
        }

        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            Log::warning('Web push send failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);

            return self::FAILED;
        }

        if ($report->isSuccess()) {
            return self::SENT;
        }

        if ($report->isSubscriptionExpired()) {
            return self::GONE;
        }

        Log::warning('Web push rejected', ['subscription' => $subscription->id, 'reason' => $report->getReason()]);

        return self::FAILED;
    }

    private function client(): WebPush
    {
        return $this->client ??= new WebPush(
            [
                'VAPID' => [
                    'subject' => config('webpush.vapid.subject'),
                    'publicKey' => config('webpush.vapid.public_key'),
                    'privateKey' => config('webpush.vapid.private_key'),
                ],
            ],
            ['TTL' => (int) config('webpush.ttl')],
        );
    }
}
