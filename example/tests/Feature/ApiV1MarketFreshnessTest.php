<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketFreshnessService;
use App\Services\MarketTradingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\MarketFreshnessServiceTest;

class ApiV1MarketFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/market/freshness')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_every_trade_region_with_timestamps_and_stale_flag(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        $jita = MarketTradingService::REGION_THE_FORGE;
        $amarr = MarketTradingService::REGION_DOMAIN;

        MarketFreshnessServiceTest::insertOrder($jita, '2026-10-21 11:00:00', 1);
        MarketFreshnessServiceTest::insertCost($jita, '2026-10-21 10:00:00');
        // Amarr costs are older than the 4 h precompute cadence + slack.
        MarketFreshnessServiceTest::insertOrder($amarr, '2026-10-21 11:00:00', 2);
        MarketFreshnessServiceTest::insertCost($amarr, '2026-10-21 06:00:00');
        MarketFreshnessServiceTest::insertGlobalPrice('2026-10-21 04:00:00');

        $user = User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/v1/market/freshness');

        $response->assertOk()
            ->assertJsonCount(count(MarketTradingService::REGIONS), 'data')
            ->assertJsonStructure([
                'data' => [['region_id', 'region_name', 'orders_as_of', 'costs_as_of', 'global_as_of', 'stale']],
                'meta' => ['stale_after_hours' => ['orders', 'costs', 'global']],
            ])
            ->assertJsonPath('meta.stale_after_hours.costs', MarketFreshnessService::COSTS_STALE_AFTER_HOURS);

        $byRegion = collect($response->json('data'))->keyBy('region_id');

        $this->assertSame(array_keys(MarketTradingService::REGIONS), $byRegion->keys()->all());
        $this->assertSame([
            'region_id' => $jita,
            'region_name' => 'The Forge (Jita)',
            'orders_as_of' => '2026-10-21T11:00:00Z',
            'costs_as_of' => '2026-10-21T10:00:00Z',
            'global_as_of' => '2026-10-21T04:00:00Z',
            'stale' => false,
        ], $byRegion[$jita]);

        $this->assertSame('2026-10-21T06:00:00Z', $byRegion[$amarr]['costs_as_of']);
        $this->assertTrue($byRegion[$amarr]['stale']);

        $hek = $byRegion[MarketTradingService::REGION_METROPOLIS];
        $this->assertNull($hek['orders_as_of']);
        $this->assertNull($hek['costs_as_of']);
        $this->assertSame('2026-10-21T04:00:00Z', $hek['global_as_of']);
        $this->assertTrue($hek['stale']);
    }
}
