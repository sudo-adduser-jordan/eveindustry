<?php

namespace App\Services\Notifications\Detectors;

use App\Models\Character;
use App\Models\StructureVulnerabilityWindow;
use App\Models\User;
use App\Services\Notifications\NotificationDetector;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\PushMessage;
use Carbon\CarbonImmutable;

/**
 * Announces recorded vulnerability windows opening and reinforcement timers
 * ending for the user's corporations. Skyhook windows have no weekday and open daily.
 */
class StructureWindowDetector implements NotificationDetector
{
    public function __construct(private NotificationType $type) {}

    public static function windowOpening(): self
    {
        return new self(NotificationType::StructureWindowOpening);
    }

    public static function timer(): self
    {
        return new self(NotificationType::StructureTimer);
    }

    public function type(): NotificationType
    {
        return $this->type;
    }

    public function detect(User $user, array $setting, CarbonImmutable $now, CarbonImmutable $since): array
    {
        $corporationIds = Character::where('user_id', $user->id)->where('corporation_id', '>', 0)->pluck('corporation_id')->unique()->all();
        if ($corporationIds === []) {
            return [];
        }

        $horizon = $now->addMinutes($setting['lead_minutes'] ?? 60);
        $messages = [];

        foreach (StructureVulnerabilityWindow::whereIn('corporation_id', $corporationIds)->get() as $window) {
            $at = $this->type === NotificationType::StructureTimer
                ? ($window->reinforce_exit_at ? CarbonImmutable::instance($window->reinforce_exit_at)->utc() : null)
                : $this->nextOpening($window, $now);

            if ($at === null || $at->lte($now) || $at->gt($horizon)) {
                continue;
            }

            $name = $window->structure_name ?: ucfirst($window->structure_type);
            $kind = $this->type === NotificationType::StructureTimer ? 'timer' : 'open';

            $messages[] = new PushMessage(
                $this->type,
                "structure:{$window->id}:{$kind}:{$at->getTimestamp()}",
                $this->type === NotificationType::StructureTimer ? 'Structure timer ending' : 'Vulnerability window opening',
                "{$name} in {$window->solar_system_name}: ".($this->type === NotificationType::StructureTimer ? 'reinforcement ends' : 'window opens').' at '.$at->format('H:i').' UTC.',
                '/app/corporate/structure-vulnerability',
            );
        }

        return $messages;
    }

    /** The next time the window opens strictly after `$now`. */
    private function nextOpening(StructureVulnerabilityWindow $window, CarbonImmutable $now): ?CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $window->start_time_utc));
        $candidate = $now->utc()->setTime($hour, $minute);

        if ($window->day_of_week !== null) {
            $candidate = $candidate->addDays(($window->day_of_week - $candidate->dayOfWeek + 7) % 7);
        }

        if ($candidate->lte($now)) {
            $candidate = $candidate->addDays($window->day_of_week === null ? 1 : 7);
        }

        return $candidate;
    }
}
