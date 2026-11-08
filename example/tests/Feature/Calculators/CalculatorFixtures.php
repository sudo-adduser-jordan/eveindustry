<?php

namespace Tests\Feature\Calculators;

use App\Models\BlueprintActivity;
use App\Models\Character;
use App\Models\EveType;
use App\Models\MarketOrder;
use App\Models\User;
use App\Services\MarketService;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Seeding and golden-file helpers shared by the calculator characterisation
 * tests and the /api/v1 calculator tests.
 *
 * Golden files live in tests/Feature/Calculators/golden. They hold
 * the exact JSON body the v1 endpoint returned before the Phase D port. To
 * re-record one (only when an intended change is made to a calculator), run
 * the test with RECORD_CALCULATOR_FIXTURES=1.
 */
trait CalculatorFixtures
{
    protected int $orderId = 9500000;

    /** A manufacturing job with job fees in both a manufacturing and a reaction system. */
    public static function feeRequest(): array
    {
        return [
            'blueprint_type_id' => 77002,
            'product_type_id' => 77001,
            'runs' => 10,
            'me_level' => 8,
            'te_level' => 16,
            'industry_skill' => 5,
            'advanced_industry_skill' => 4,
            'structure_me_bonus' => 1,
            'structure_te_bonus' => 15,
            'reaction_me_bonus' => 2.2,
            'solar_system_id' => self::MFG_SYSTEM,
            'tax_rate' => 0.25,
            'reaction_solar_system_id' => self::REACTION_SYSTEM,
            'reaction_tax_rate' => 1,
        ];
    }

    public static function reactionBrowserRequest(): array
    {
        return [
            'runs' => 20,
            'rig_tier' => 't2',
            'security_space' => 'lowsec',
            'facility_tax' => 1.5,
            'system_cost_index' => 6,
        ];
    }

    public static function reactionsRequest(): array
    {
        return [
            'formula_type_id' => 77006,
            'product_type_id' => 77005,
            'runs' => 15,
            'reactions_skill' => 4,
            'rig_bonus' => 2.4,
            'structure_te_bonus' => 20,
        ];
    }

    public static function plannerRequest(): array
    {
        return [
            'entries' => [
                ['formula_type_id' => 77010, 'formula_name' => 'Advanced', 'slots' => 2, 'runs_per_slot' => 30],
                ['formula_type_id' => 77006, 'slots' => 1, 'runs_per_slot' => 5],
            ],
            'structure_type' => 'tatara',
            'rig_tier' => 't1',
            'security_space' => 'nullsec',
            'facility_tax' => 2,
            'system_cost_index' => 3.5,
        ];
    }

    protected function makeUser(string $email = 'calc@example.com'): User
    {
        return User::create(['name' => 'Calc User', 'email' => $email, 'is_active' => true]);
    }

    /**
     * Drop the logged-in user so the next request is a guest one.
     */
    protected function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function makeCharacter(User $user, int $characterId, array $scopes = []): Character
    {
        return Character::create([
            'character_id' => $characterId,
            'user_id' => $user->id,
            'name' => "Pilot {$characterId}",
            'corporation_id' => 98000001,
            'scopes' => $scopes,
        ]);
    }

    protected function sellOrder(int $typeId, float $price, int $regionId = MarketService::DEFAULT_REGION_ID, int $volume = 1000): void
    {
        MarketOrder::create([
            'order_id' => ++$this->orderId,
            'region_id' => $regionId,
            'type_id' => $typeId,
            'is_buy_order' => false,
            'price' => $price,
            'volume_remain' => $volume,
            'volume_total' => $volume,
            'issued' => now(),
        ]);
    }

    protected function blueprint(int $blueprintTypeId, int $activityId, array $materials, array $products, int $time = 600): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => $blueprintTypeId,
            'activity_id' => $activityId,
            'time' => $time,
            'materials' => $materials,
            'products' => $products,
        ]);
    }

    protected function type(int $typeId, string $name, array $extra = []): void
    {
        EveType::create(array_merge(['type_id' => $typeId, 'name' => $name, 'published' => true], $extra));
    }

    /**
     * Fake every ESI call: global adjusted prices, industry cost indices,
     * empty regional orders and history (so fetchAndStoreRegionalPrices
     * does not hit the network; prices then come from seeded market_orders).
     *
     * @param  array<int, float>  $adjustedPrices  type_id => adjusted_price
     * @param  array<int, array<string, float>>  $costIndices  solar_system_id => [activity => index]
     */
    protected function fakeEsi(array $adjustedPrices = [], array $costIndices = []): void
    {
        Http::fake(function ($request) use ($adjustedPrices, $costIndices) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (str_contains($path, '/markets/prices')) {
                $rows = [];
                foreach ($adjustedPrices as $typeId => $price) {
                    $rows[] = ['type_id' => $typeId, 'adjusted_price' => $price, 'average_price' => $price];
                }

                return Http::response($rows);
            }

            if (str_contains($path, '/industry/systems')) {
                $rows = [];
                foreach ($costIndices as $systemId => $indices) {
                    $ci = [];
                    foreach ($indices as $activity => $index) {
                        $ci[] = ['activity' => $activity, 'cost_index' => $index];
                    }
                    $rows[] = ['solar_system_id' => $systemId, 'cost_indices' => $ci];
                }

                return Http::response($rows);
            }

            return Http::response([]);
        });
    }

    /** Manufacturing system with a manufacturing cost index. */
    protected const MFG_SYSTEM = 30000142;

    /** Reaction system with a reaction cost index. */
    protected const REACTION_SYSTEM = 30002000;

    /**
     * A small manufacturing + reaction tree with prices, volumes, adjusted
     * prices and cost indices:
     *
     *   Product 77001 ← blueprint 77002: Component 77003 ×5, Tritanium 34 ×1000, Composite 77005 ×20
     *   Component 77003 ← blueprint 77004: Tritanium 34 ×100, Pyerite 35 ×50
     *   Composite 77005 (×200 per run) ← reaction formula 77006: Moon goo 16634 ×100, Fuel block 4051 ×5
     *   Composite 77011 (×10 per run) ← reaction formula 77010: Composite 77005 ×100, Fuel block 4051 ×5
     */
    protected function seedIndustryTree(): void
    {
        $this->blueprint(77002, BlueprintActivity::ACTIVITY_MANUFACTURING, [
            ['type_id' => 77003, 'name' => 'Test Component', 'quantity' => 5],
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 1000],
            ['type_id' => 77005, 'name' => 'Test Composite', 'quantity' => 20],
        ], [['type_id' => 77001, 'name' => 'Test Product', 'quantity' => 1]], 6000);

        $this->blueprint(77004, BlueprintActivity::ACTIVITY_MANUFACTURING, [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 50],
        ], [['type_id' => 77003, 'name' => 'Test Component', 'quantity' => 1]], 300);

        $this->blueprint(77006, BlueprintActivity::ACTIVITY_REACTION, [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ['type_id' => 4051, 'name' => 'Fuel Block', 'quantity' => 5],
        ], [['type_id' => 77005, 'name' => 'Test Composite', 'quantity' => 200]], 10800);

        $this->blueprint(77010, BlueprintActivity::ACTIVITY_REACTION, [
            ['type_id' => 77005, 'name' => 'Test Composite', 'quantity' => 100],
            ['type_id' => 4051, 'name' => 'Fuel Block', 'quantity' => 5],
        ], [['type_id' => 77011, 'name' => 'Test Advanced Composite', 'quantity' => 10]], 10800);

        $this->type(77001, 'Test Product', ['volume' => 50, 'market_group_id' => 100]);
        $this->type(77002, 'Test Product Blueprint');
        $this->type(77003, 'Test Component', ['volume' => 5, 'packaged_volume' => 2, 'market_group_id' => 100]);
        $this->type(77004, 'Test Component Blueprint');
        $this->type(77005, 'Test Composite', ['volume' => 1]);
        $this->type(77006, 'Test Composite Reaction Formula', ['group_id' => 1888]);
        $this->type(77010, 'Test Advanced Composite Reaction Formula', ['group_id' => 1888]);
        $this->type(77011, 'Test Advanced Composite', ['volume' => 0.5]);
        $this->type(34, 'Tritanium', ['volume' => 0.01]);
        $this->type(35, 'Pyerite', ['volume' => 0.01]);
        $this->type(16634, 'Atmospheric Gases', ['volume' => 0.05]);
        $this->type(4051, 'Fuel Block', ['volume' => 5]);

        foreach ([
            34 => 5.5, 35 => 11.25, 77003 => 1500.0, 77005 => 300.0, 16634 => 42.0,
            4051 => 15000.0, 77001 => 125000.0, 77011 => 9000.0,
        ] as $typeId => $price) {
            $this->sellOrder($typeId, $price);
        }

        $this->fakeEsi(
            [34 => 4.0, 35 => 9.0, 77003 => 1200.0, 77005 => 250.0, 16634 => 40.0, 4051 => 14000.0],
            [
                self::MFG_SYSTEM => ['manufacturing' => 0.05, 'reaction' => 0.01],
                self::REACTION_SYSTEM => ['manufacturing' => 0.02, 'reaction' => 0.08],
            ],
        );
    }

    /**
     * Assert the response body is byte-for-byte the recorded golden file.
     */
    protected function assertMatchesGolden(string $name, TestResponse $response): void
    {
        $path = __DIR__.'/golden/'.$name.'.json';
        $actual = $response->getContent();

        if (getenv('RECORD_CALCULATOR_FIXTURES')) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $actual);
        }

        $this->assertFileExists($path, "Golden file {$name}.json is missing; record it with RECORD_CALCULATOR_FIXTURES=1.");
        $this->assertSame(file_get_contents($path), $actual, "Response for {$name} differs from the recorded golden file.");
    }
}
