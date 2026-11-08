<?php

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Finds the events one notification type should announce for a user. Detectors
 * read data the app already holds (database rows and EsiService-cached ESI
 * data); they never call ESI directly and never record what was sent. The
 * dispatcher de-duplicates on each message's key.
 */
interface NotificationDetector
{
    public function type(): NotificationType;

    /**
     * @param  array{enabled: bool, lead_minutes: ?int, character_ids: list<int>}  $setting
     * @param  CarbonImmutable  $since  Events before this are ignored (never notify about the past).
     * @return list<PushMessage>
     */
    public function detect(User $user, array $setting, CarbonImmutable $now, CarbonImmutable $since): array;
}
