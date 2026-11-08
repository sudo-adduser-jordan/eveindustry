<?php

namespace Tests\Feature\LegacyApi\Market;

use App\Models\EveType;
use App\Models\MarketHistory;
use App\Models\MarketOrder;
use App\Models\PublicContract;
use App\Models\PublicContractItem;
use App\Models\User;
use App\Services\MarketTradingService;
use Illuminate\Support\Facades\Http;

/**
 * Shared seed data for the market legacy and v1 feature tests.
 */
trait MarketFixtures
{
    private int $nextOrderId = 1;

    protected function marketUser(): User
    {
        return User::firstOrCreate(['email' => 'pilot@test.com'], ['name' => 'Pilot', 'is_active' => true]);
    }

    protected function fakeEsi(array $responses = []): void
    {
        Http::preventStrayRequests();
        Http::fake($responses + ['*' => Http::response([], 404)]);
    }

    protected function seedType(int $typeId, string $name, float $volume = 0.01): void
    {
        EveType::create(['type_id' => $typeId, 'name' => $name, 'published' => true, 'volume' => $volume]);
    }

    protected function seedOrder(int $typeId, bool $isBuy, float $price, int $regionId = MarketTradingService::REGION_THE_FORGE, int $volumeRemain = 100, ?string $issued = null): int
    {
        $orderId = $this->nextOrderId++;
        MarketOrder::create([
            'order_id' => $orderId,
            'region_id' => $regionId,
            'type_id' => $typeId,
            'is_buy_order' => $isBuy,
            'price' => $price,
            'volume_remain' => $volumeRemain,
            'volume_total' => $volumeRemain,
            'min_volume' => 1,
            'location_id' => MarketTradingService::JITA_STATION_ID,
            'duration' => 90,
            'range' => 'station',
            'issued' => $issued ?? now(),
            'batch_id' => 'test-batch',
        ]);

        return $orderId;
    }

    /**
     * @param  array<string, int>  $volumesByDate  date => volume
     */
    protected function seedHistory(int $typeId, array $volumesByDate, int $regionId = MarketTradingService::REGION_THE_FORGE): void
    {
        foreach ($volumesByDate as $date => $volume) {
            MarketHistory::create([
                'region_id' => $regionId,
                'type_id' => $typeId,
                'date' => $date,
                'average' => 100.0,
                'highest' => 110.0,
                'lowest' => 90.0,
                'volume' => $volume,
                'order_count' => 7,
            ]);
        }
    }

    protected function seedSingleItemContract(int $contractId, int $typeId, int $quantity, float $price, bool $isIncluded = true): void
    {
        PublicContract::create([
            'contract_id' => $contractId,
            'region_id' => MarketTradingService::REGION_THE_FORGE,
            'type' => 'item_exchange',
            'price' => $price,
        ]);

        PublicContractItem::create([
            'contract_id' => $contractId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'is_included' => $isIncluded,
        ]);
    }

    /**
     * Jita station-trading scenario. Expected results (defaults): 34, 38, 35.
     * - 34 Tritanium: 150/100 spread, 20/day volume -> daily profit 742
     * - 35 Pyerite: 20/10 spread, no history -> kept with volume 0
     * - 36 Mexallon: good spread but 1/day volume -> dropped by min_daily_volume
     * - 37 Isogen: 101/100 spread -> below min margin
     * - 38 (no eve_types row): 1000/500 spread, no history -> "Type #38"
     * - 34 in Amarr: must not leak into the Jita scan
     */
    protected function seedStationTradingScenario(): void
    {
        $this->seedType(34, 'Tritanium');
        $this->seedType(35, 'Pyerite');
        $this->seedType(36, 'Mexallon');
        $this->seedType(37, 'Isogen');

        $this->seedOrder(34, false, 150.0);
        $this->seedOrder(34, false, 160.0);
        $this->seedOrder(34, true, 100.0);
        $this->seedOrder(34, true, 90.0);
        $this->seedHistory(34, ['2026-10-18' => 10, '2026-10-19' => 20, '2026-10-20' => 30]);

        $this->seedOrder(35, false, 20.0);
        $this->seedOrder(35, true, 10.0);

        $this->seedOrder(36, false, 200.0);
        $this->seedOrder(36, true, 100.0);
        $this->seedHistory(36, ['2026-10-19' => 1, '2026-10-20' => 1]);

        $this->seedOrder(37, false, 101.0);
        $this->seedOrder(37, true, 100.0);

        $this->seedOrder(38, false, 1000.0);
        $this->seedOrder(38, true, 500.0);

        $this->seedOrder(34, false, 5.0, MarketTradingService::REGION_DOMAIN);
        $this->seedOrder(34, true, 1.0, MarketTradingService::REGION_DOMAIN);
    }

    /**
     * Jita -> Amarr arbitrage scenario. Expected results (defaults): 34, 35.
     * - 34 Tritanium: buy 100 in Jita, sell 150 in Amarr, 10/day -> daily profit 446
     * - 35 (no eve_types row, name via faked ESI): buy 10, sell 20, no history
     * - 36 Mexallon: 100 -> 101, loses money after tax
     */
    protected function seedArbitrageScenario(): void
    {
        $jita = MarketTradingService::REGION_THE_FORGE;
        $amarr = MarketTradingService::REGION_DOMAIN;

        $this->seedType(34, 'Tritanium');
        $this->seedType(36, 'Mexallon');

        $this->seedOrder(34, false, 100.0, $jita);
        $this->seedOrder(34, false, 120.0, $jita);
        $this->seedOrder(34, true, 80.0, $jita);
        $this->seedOrder(34, true, 150.0, $amarr);
        $this->seedOrder(34, true, 140.0, $amarr);
        $this->seedOrder(34, false, 90.0, $amarr);
        $this->seedHistory(34, ['2026-10-19' => 10, '2026-10-20' => 10], $amarr);

        $this->seedOrder(35, false, 10.0, $jita);
        $this->seedOrder(35, true, 20.0, $amarr);

        $this->seedOrder(36, false, 100.0, $jita);
        $this->seedOrder(36, true, 101.0, $amarr);

        $this->fakeEsi([
            '*/universe/types/35/*' => Http::response(['type_id' => 35, 'name' => 'Pyerite', 'volume' => 0.01]),
        ]);
    }
}
