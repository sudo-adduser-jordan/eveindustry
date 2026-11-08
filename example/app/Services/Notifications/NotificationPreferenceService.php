<?php

namespace App\Services\Notifications;

use App\Models\Character;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\CarbonInterface;

/** Resolves what a user has opted into: registry defaults overlaid with the rows they saved. */
class NotificationPreferenceService
{
    /**
     * @return array<string, array{enabled: bool, lead_minutes: ?int, character_ids: list<int>}>
     */
    public function effective(User $user): array
    {
        $stored = NotificationPreference::where('user_id', $user->id)->get()->keyBy('type');
        $effective = [];

        foreach (NotificationType::cases() as $type) {
            $row = $stored->get($type->value);

            $effective[$type->value] = [
                'enabled' => $row ? (bool) $row->enabled : $type->defaultEnabled(),
                'lead_minutes' => $type->leadOptions() === null ? null : ($row?->lead_minutes ?? $type->defaultLeadMinutes()),
                'character_ids' => array_values(array_map('intval', $row?->character_ids ?? [])),
            ];
        }

        return $effective;
    }

    /** Corporation notifications only make sense for a user with a character in a corporation. */
    public function isAvailable(User $user, NotificationType $type): bool
    {
        if (! $type->requiresCorporation()) {
            return true;
        }

        return Character::where('user_id', $user->id)->where('corporation_id', '>', 0)->exists();
    }

    /**
     * Applies a partial change set, already validated against the registry.
     *
     * @param  array<string, array{enabled?: bool, lead_minutes?: ?int, character_ids?: list<int>}>  $changes
     */
    public function update(User $user, array $changes): void
    {
        $current = $this->effective($user);

        foreach ($changes as $key => $change) {
            $type = NotificationType::from($key);
            $merged = array_merge($current[$key], $change);

            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'type' => $type->value],
                [
                    'enabled' => $merged['enabled'],
                    'lead_minutes' => $type->leadOptions() === null ? null : $merged['lead_minutes'],
                    'character_ids' => $type->supportsCharacters() ? array_values($merged['character_ids']) : null,
                ],
            );
        }
    }

    /** @return array{start: ?string, end: ?string, timezone: string} */
    public function quietHours(User $user): array
    {
        $setting = NotificationSetting::find($user->id);

        return [
            'start' => $setting?->quiet_start,
            'end' => $setting?->quiet_end,
            'timezone' => $setting?->timezone ?? 'UTC',
        ];
    }

    /** @param  array{start: ?string, end: ?string, timezone: string}  $quiet */
    public function updateQuietHours(User $user, array $quiet): void
    {
        NotificationSetting::updateOrCreate(
            ['user_id' => $user->id],
            ['quiet_start' => $quiet['start'], 'quiet_end' => $quiet['end'], 'timezone' => $quiet['timezone']],
        );
    }

    public function isQuiet(User $user, CarbonInterface $now): bool
    {
        $quiet = $this->quietHours($user);
        if ($quiet['start'] === null || $quiet['end'] === null || $quiet['start'] === $quiet['end']) {
            return false;
        }

        $local = $now->copy()->setTimezone($quiet['timezone'])->format('H:i');

        // A window like 22:00-07:00 wraps past midnight.
        return $quiet['start'] < $quiet['end']
            ? $local >= $quiet['start'] && $local < $quiet['end']
            : $local >= $quiet['start'] || $local < $quiet['end'];
    }
}
