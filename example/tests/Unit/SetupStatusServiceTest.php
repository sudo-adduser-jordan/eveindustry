<?php

namespace Tests\Unit;

use App\Models\MarketOrderSweep;
use App\Models\MarketPrice;
use App\Services\SetupStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetupStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_is_not_ready(): void
    {
        $this->assertSame(
            ['ready' => false, 'steps' => ['sde' => false, 'prices' => false, 'orders' => false]],
            app(SetupStatusService::class)->status(),
        );
    }

    public function test_prices_and_completed_sweeps_flip_their_steps(): void
    {
        DB::table('market_prices')->insert(['type_id' => 34, 'average_price' => 5, 'adjusted_price' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(MarketPrice::query()->exists());
        DB::table('market_order_sweeps')->insert([
            'sweep_id' => 's1', 'region_id' => 10000002, 'generation' => 1,
            'status' => MarketOrderSweep::STATUS_COMPLETED, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $steps = app(SetupStatusService::class)->status()['steps'];

        $this->assertTrue($steps['prices']);
        $this->assertTrue($steps['orders']);
        $this->assertFalse($steps['sde']);
    }
}
