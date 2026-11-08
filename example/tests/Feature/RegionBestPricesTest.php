<?php

namespace Tests\Feature;

use App\Services\EsiService;
use App\Services\MarketTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Market\MarketFixtures;
use Tests\TestCase;

/**
 * EsiService::getRegionBestPrices(), the grouped query behind region arbitrage.
 */
class RegionBestPricesTest extends TestCase
{
    use MarketFixtures;
    use RefreshDatabase;

    public function test_returns_the_lowest_sell_and_highest_buy_per_type(): void
    {
        $this->seedOrder(34, false, 6.0);
        $this->seedOrder(34, false, 5.5);
        $this->seedOrder(34, true, 4.0);
        $this->seedOrder(34, true, 4.5);
        $this->seedOrder(35, false, 12.0);
        $this->seedOrder(34, false, 1.0, MarketTradingService::REGION_DOMAIN);

        $esi = app(EsiService::class);

        $this->assertSame([34 => 5.5, 35 => 12.0], $esi->getRegionBestPrices(MarketTradingService::REGION_THE_FORGE, false));
        $this->assertSame([34 => 4.5], $esi->getRegionBestPrices(MarketTradingService::REGION_THE_FORGE, true));
        $this->assertSame([34 => 1.0], $esi->getRegionBestPrices(MarketTradingService::REGION_DOMAIN, false));
        $this->assertSame([], $esi->getRegionBestPrices(MarketTradingService::REGION_HEIMATAR, false));
    }
}
