<?php

namespace App\Services\Notifications;

/** One notification to deliver. `dedupeKey` is unique per real-world event. */
final class PushMessage
{
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $dedupeKey,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {}

    /** The JSON payload the service worker's push handler expects. */
    public function payload(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->dedupeKey,
        ];
    }
}
