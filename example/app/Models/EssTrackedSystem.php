<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EssTrackedSystem extends Model
{
    protected $table = 'ess_tracked_systems';

    protected $fillable = [
        'corporation_id',
        'solar_system_id',
        'solar_system_name',
        'cycle_minutes',
        'cycle_anchor_at',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'corporation_id' => 'integer',
            'solar_system_id' => 'integer',
            'cycle_minutes' => 'integer',
            'cycle_anchor_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    public function payoutEvents(): HasMany
    {
        return $this->hasMany(EssPayoutEvent::class)->orderByDesc('occurred_at');
    }

    /**
     * Compute the next payout time relative to $now using the anchor timestamp
     * and the configured cycle length. Returns a CarbonImmutable.
     */
    public function nextPayoutAt(?\DateTimeInterface $now = null): CarbonImmutable
    {
        $cycle = max(1, (int) $this->cycle_minutes);
        $anchor = CarbonImmutable::parse($this->cycle_anchor_at);
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();

        if ($now->lessThanOrEqualTo($anchor)) {
            return $anchor;
        }

        $diffSeconds = $now->getTimestamp() - $anchor->getTimestamp();
        $cycleSeconds = $cycle * 60;
        $cyclesElapsed = intdiv($diffSeconds, $cycleSeconds) + 1;

        return $anchor->addSeconds($cyclesElapsed * $cycleSeconds);
    }

    public function secondsUntilNextPayout(?\DateTimeInterface $now = null): int
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();

        return max(0, $this->nextPayoutAt($now)->getTimestamp() - $now->getTimestamp());
    }
}
