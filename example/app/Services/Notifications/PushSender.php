<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;

interface PushSender
{
    public const SENT = 'sent';

    /** The push service says the subscription no longer exists (404/410). */
    public const GONE = 'gone';

    public const FAILED = 'failed';

    /** @param  array{title: string, body: string, url: string, tag: string}  $payload */
    public function send(PushSubscription $subscription, array $payload): string;
}
