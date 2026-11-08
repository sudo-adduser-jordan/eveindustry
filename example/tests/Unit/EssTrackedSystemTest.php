<?php

namespace Tests\Unit;

use App\Models\EssTrackedSystem;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class EssTrackedSystemTest extends TestCase
{
    public function test_next_payout_at_returns_anchor_when_now_equals_anchor(): void
    {
        $anchor = CarbonImmutable::parse('2026-01-01 12:00:00');
        $system = $this->makeSystem($anchor, 20);

        $next = $system->nextPayoutAt($anchor);

        $this->assertEquals($anchor, $next);
    }

    public function test_next_payout_at_returns_anchor_when_now_is_before_anchor(): void
    {
        $anchor = CarbonImmutable::parse('2026-01-01 12:00:00');
        $system = $this->makeSystem($anchor, 20);

        $next = $system->nextPayoutAt(CarbonImmutable::parse('2026-01-01 11:30:00'));

        $this->assertEquals($anchor, $next);
    }

    public function test_next_payout_at_rolls_forward_one_cycle_when_inside_cycle(): void
    {
        $anchor = CarbonImmutable::parse('2026-01-01 12:00:00');
        $system = $this->makeSystem($anchor, 20);

        $next = $system->nextPayoutAt(CarbonImmutable::parse('2026-01-01 12:05:00'));

        $this->assertEquals('2026-01-01 12:20:00', $next->toDateTimeString());
    }

    public function test_next_payout_at_rolls_forward_multiple_cycles(): void
    {
        $anchor = CarbonImmutable::parse('2026-01-01 12:00:00');
        $system = $this->makeSystem($anchor, 20);

        $next = $system->nextPayoutAt(CarbonImmutable::parse('2026-01-01 13:05:00'));

        // 65 minutes past anchor with 20-minute cycle → next at +80 minutes.
        $this->assertEquals('2026-01-01 13:20:00', $next->toDateTimeString());
    }

    public function test_seconds_until_next_payout_is_non_negative(): void
    {
        $anchor = CarbonImmutable::parse('2026-01-01 12:00:00');
        $system = $this->makeSystem($anchor, 20);

        $seconds = $system->secondsUntilNextPayout(CarbonImmutable::parse('2026-01-01 12:10:00'));

        $this->assertSame(10 * 60, $seconds);
    }

    private function makeSystem(CarbonImmutable $anchor, int $cycle): EssTrackedSystem
    {
        $system = new EssTrackedSystem();
        $system->setRawAttributes([
            'cycle_minutes' => $cycle,
            'cycle_anchor_at' => $anchor->toDateTimeString(),
        ]);

        return $system;
    }
}
