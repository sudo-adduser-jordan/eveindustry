<?php

namespace App\Services\Notifications\Detectors;

use App\Models\User;
use App\Services\Notifications\NotificationDetector;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\PushMessage;
use App\Services\PlanetaryService;
use App\Services\RefreshEligibility;
use Carbon\CarbonImmutable;

/** One message per colony whose extractors stop within the lead time (or stopped since `$since`). */
class PiExtractorExpiringDetector implements NotificationDetector
{
    public function __construct(private PlanetaryService $planetary) {}

    public function type(): NotificationType
    {
        return NotificationType::PiExtractorExpiring;
    }

    public function detect(User $user, array $setting, CarbonImmutable $now, CarbonImmutable $since): array
    {
        $horizon = $now->addMinutes($setting['lead_minutes'] ?? 60);
        $messages = [];

        foreach (RefreshEligibility::characters($user->id, 'pi')->get() as $character) {
            if ($setting['character_ids'] !== [] && ! in_array((int) $character->character_id, $setting['character_ids'], true)) {
                continue;
            }

            foreach ($this->planetary->getCharacterPlanetaryData($character)['planets'] ?? [] as $planet) {
                $expiries = [];
                foreach ($planet['pins'] as $pin) {
                    $expiry = $pin['extractor']['expiry_time'] ?? null;
                    if ($expiry === null) {
                        continue;
                    }
                    $at = CarbonImmutable::parse($expiry, 'UTC');
                    if ($at->gt($since) && $at->lte($horizon)) {
                        $expiries[] = $at;
                    }
                }

                if ($expiries === []) {
                    continue;
                }

                $earliest = min($expiries);
                $count = count($expiries);
                $when = $earliest->lte($now) ? 'have stopped' : 'stop '.$earliest->diffForHumans($now, ['syntax' => CarbonImmutable::DIFF_ABSOLUTE]).' from now';

                $messages[] = new PushMessage(
                    $this->type(),
                    "pi:{$character->character_id}:{$planet['planet_id']}:{$earliest->getTimestamp()}",
                    'Planetary extractors expiring',
                    "{$count} extractor".($count === 1 ? '' : 's')." on {$planet['planet_name']} ({$character->name}) {$when}.",
                    '/app/planetary',
                );
            }
        }

        return $messages;
    }
}
