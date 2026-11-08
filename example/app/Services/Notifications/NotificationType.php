<?php

namespace App\Services\Notifications;

/**
 * Every push notification a user can opt into. The single source of truth for
 * the dispatcher, request validation and the settings page (served through
 * GET /api/v1/notifications/config), so a new type needs no frontend change.
 */
enum NotificationType: string
{
    case IndustryJobCompleted = 'industry.job_completed';
    case PiExtractorExpiring = 'pi.extractor_expiring';
    case StructureWindowOpening = 'structure.window_opening';
    case StructureTimer = 'structure.timer';

    public function label(): string
    {
        return match ($this) {
            self::IndustryJobCompleted => 'Industry job completed',
            self::PiExtractorExpiring => 'Planetary extractor expiring',
            self::StructureWindowOpening => 'Structure vulnerability window opening',
            self::StructureTimer => 'Structure timer ending',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::IndustryJobCompleted => 'A manufacturing, research, invention or reaction job has finished and is ready to deliver.',
            self::PiExtractorExpiring => 'Extractor heads on one of your colonies are about to stop, or have stopped.',
            self::StructureWindowOpening => 'A recorded structure vulnerability window for your corporation is about to open.',
            self::StructureTimer => 'A recorded reinforcement timer for your corporation is about to end.',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::IndustryJobCompleted => 'Industry',
            self::PiExtractorExpiring => 'Planetary',
            self::StructureWindowOpening, self::StructureTimer => 'Corporation',
        };
    }

    /** Job completions are on out of the box; everything else is opt-in. */
    public function defaultEnabled(): bool
    {
        return $this === self::IndustryJobCompleted;
    }

    /** Minutes of warning the user can pick, or null for events that fire when they happen. */
    public function leadOptions(): ?array
    {
        return $this === self::IndustryJobCompleted ? null : [15, 60, 360];
    }

    public function defaultLeadMinutes(): ?int
    {
        return $this->leadOptions() === null ? null : 60;
    }

    /** Whether the user can limit the type to some of their characters. */
    public function supportsCharacters(): bool
    {
        return in_array($this, [self::IndustryJobCompleted, self::PiExtractorExpiring], true);
    }

    public function requiresCorporation(): bool
    {
        return $this->category() === 'Corporation';
    }
}
