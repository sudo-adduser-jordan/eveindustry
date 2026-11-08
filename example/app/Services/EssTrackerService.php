<?php

namespace App\Services;

use App\Models\Character;
use App\Models\EssTrackedSystem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Read side of the corporation ESS tracker: which corporation a user acts
 * for and the systems that corporation tracks.
 */
class EssTrackerService
{
    /** Payout events shown per system. */
    public const RECENT_PAYOUTS = 5;

    /**
     * The user's corporation: the main character's, or any character's when
     * there is no main (same rule as Concerns\ResolvesCorporation).
     */
    public function corporationIdForUser(int $userId): ?int
    {
        $character = Character::where('user_id', $userId)->where('is_main', true)->first()
            ?? Character::where('user_id', $userId)->first();

        return $character?->corporation_id;
    }

    /**
     * @return Collection<int, EssTrackedSystem>
     */
    public function systemsForCorporation(int $corporationId): Collection
    {
        return $this->systemsQuery($corporationId)->get();
    }

    public function paginateSystemsForCorporation(int $corporationId, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->systemsQuery($corporationId)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Combine separate hours and minutes inputs into one cycle length.
     *
     * With `cycle_hours` present the total is hours * 60 + minutes; otherwise
     * `cycle_minutes` is the total. The total must be 1 to 1440 minutes.
     *
     * @param  array<string, mixed>  $validated
     */
    public function resolveCycleMinutes(array $validated): int
    {
        $minutes = (int) $validated['cycle_minutes'];
        if (array_key_exists('cycle_hours', $validated) && $validated['cycle_hours'] !== null) {
            $minutes = ((int) $validated['cycle_hours']) * 60 + $minutes;
        }

        if ($minutes < 1 || $minutes > 1440) {
            throw ValidationException::withMessages([
                'cycle_minutes' => 'Cycle length must be between 1 and 1440 minutes.',
            ]);
        }

        return $minutes;
    }

    /**
     * The anchor the next-payout maths counts cycles from.
     *
     * Prefers `next_payout_in_hours` / `next_payout_in_minutes` (relative to
     * now): the anchor is one cycle before that payout, so the model's forward
     * iteration lands on it. Falls back to an explicit `cycle_anchor_at`, then
     * to `$default`, then to now.
     *
     * @param  array<string, mixed>  $validated
     */
    public function resolveCycleAnchor(array $validated, int $cycleMinutes, ?\DateTimeInterface $default = null): CarbonImmutable
    {
        $hasHours = array_key_exists('next_payout_in_hours', $validated) && $validated['next_payout_in_hours'] !== null;
        $hasMinutes = array_key_exists('next_payout_in_minutes', $validated) && $validated['next_payout_in_minutes'] !== null;

        if ($hasHours || $hasMinutes) {
            $secondsUntilNext = ((int) ($validated['next_payout_in_hours'] ?? 0)) * 3600
                + ((int) ($validated['next_payout_in_minutes'] ?? 0)) * 60;

            return CarbonImmutable::now()->addSeconds($secondsUntilNext)->subSeconds($cycleMinutes * 60);
        }

        if (isset($validated['cycle_anchor_at'])) {
            return CarbonImmutable::parse($validated['cycle_anchor_at']);
        }

        if ($default !== null) {
            return CarbonImmutable::parse($default);
        }

        return CarbonImmutable::now();
    }

    private function systemsQuery(int $corporationId): Builder
    {
        return EssTrackedSystem::where('corporation_id', $corporationId)
            ->with(['payoutEvents' => fn ($q) => $q->limit(self::RECENT_PAYOUTS)])
            ->orderBy('solar_system_name')
            ->orderBy('id');
    }
}
