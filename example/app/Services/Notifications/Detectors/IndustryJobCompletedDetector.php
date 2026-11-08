<?php

namespace App\Services\Notifications\Detectors;

use App\Models\User;
use App\Services\IndustryService;
use App\Services\Notifications\NotificationDetector;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\PushMessage;
use Carbon\CarbonImmutable;

class IndustryJobCompletedDetector implements NotificationDetector
{
    public function __construct(private IndustryService $industry) {}

    public function type(): NotificationType
    {
        return NotificationType::IndustryJobCompleted;
    }

    public function detect(User $user, array $setting, CarbonImmutable $now, CarbonImmutable $since): array
    {
        $messages = [];

        foreach ($this->industry->getFinishedJobsForUser($user->id, $since, $now) as $job) {
            if ($setting['character_ids'] !== [] && ! in_array($job['character_id'], $setting['character_ids'], true)) {
                continue;
            }

            $messages[] = new PushMessage(
                $this->type(),
                "industry:{$job['job_id']}:done",
                'Industry job finished',
                "{$job['activity_name']}: {$job['item_name']} ({$job['character_name']}) is ready to deliver.",
                '/app/industry/jobs',
            );
        }

        return $messages;
    }
}
