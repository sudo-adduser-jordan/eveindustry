<?php

namespace Tests\Unit;

use App\Models\EveType;
use App\Services\ContractAppraisalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractAppraisalServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractAppraisalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContractAppraisalService;
    }

    public function test_parse_items_groups_duplicates(): void
    {
        $text = "Tritanium\nTritanium\nPyerite\nTritanium\n";
        $result = $this->service->parseItems($text);

        $this->assertEquals(['Tritanium' => 3, 'Pyerite' => 1], $result);
    }

    public function test_parse_items_trims_whitespace(): void
    {
        $text = "  Tritanium  \n  Pyerite  \n";
        $result = $this->service->parseItems($text);

        $this->assertEquals(['Tritanium' => 1, 'Pyerite' => 1], $result);
    }

    public function test_parse_items_ignores_empty_lines(): void
    {
        $text = "Tritanium\n\n\nPyerite\n\n";
        $result = $this->service->parseItems($text);

        $this->assertEquals(['Tritanium' => 1, 'Pyerite' => 1], $result);
    }

    public function test_parse_items_empty_input(): void
    {
        $this->assertEquals([], $this->service->parseItems(''));
        $this->assertEquals([], $this->service->parseItems("\n\n"));
    }

    public function test_appraise_returns_unmatched_for_unknown_items(): void
    {
        $result = $this->service->appraise("Unknown Item\n");

        $this->assertEmpty($result['items']);
        $this->assertEquals(['Unknown Item'], $result['unmatched']);
        $this->assertEquals(0, $result['total_value']);
    }

    public function test_appraise_matches_items_by_name(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        $result = $this->service->appraise("Tritanium\nTritanium\n");

        $this->assertCount(1, $result['items']);
        $this->assertEquals('Tritanium', $result['items'][0]['name']);
        $this->assertEquals(34, $result['items'][0]['type_id']);
        $this->assertEquals(2, $result['items'][0]['quantity']);
        $this->assertEmpty($result['unmatched']);
    }

    public function test_appraise_calculates_contract_pricing(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        // Create two contracts each containing ONLY Tritanium
        $this->createSingleItemContract(100001, 34, 100, 500000.00);  // 5000 per unit
        $this->createSingleItemContract(100002, 34, 200, 1200000.00); // 6000 per unit

        $result = $this->service->appraise("Tritanium\nTritanium\nTritanium\n");

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertEquals(3, $item['quantity']);
        $this->assertEquals(2, $item['contracts_found']);
        // Average unit price: (5000 + 6000) / 2 = 5500
        $this->assertEquals(5500.00, $item['unit_price']);
        $this->assertEquals(16500.00, $item['line_total']);
        $this->assertEquals(16500.00, $result['total_value']);
    }

    public function test_appraise_excludes_multi_type_contracts(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        // Contract with only Tritanium (should be included)
        $this->createSingleItemContract(100001, 34, 100, 500000.00);

        // Contract with Tritanium AND Pyerite (should be excluded)
        $this->createContract(100002, 999999.00);
        $this->createItem(100002, 34, 50, true);
        $this->createItem(100002, 35, 25, true);

        $result = $this->service->appraise("Tritanium\n");

        $item = $result['items'][0];
        $this->assertEquals(1, $item['contracts_found']);
        $this->assertEquals(5000.00, $item['unit_price']); // Only from the single-item contract
    }

    public function test_appraise_returns_zero_when_no_contracts(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        $result = $this->service->appraise("Tritanium\n");

        $this->assertCount(1, $result['items']);
        $this->assertEquals(0, $result['items'][0]['unit_price']);
        $this->assertEquals(0, $result['items'][0]['contracts_found']);
    }

    public function test_appraise_sell_filter_only_includes_sell_contracts(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        // Sell contract: items are included (is_included=true)
        $this->createSingleItemContract(100001, 34, 100, 500000.00, true);

        // Buy contract: items are NOT included (is_included=false)
        $this->createSingleItemContract(100002, 34, 50, 200000.00, false);

        $result = $this->service->appraise("Tritanium\n", 'sell');

        $item = $result['items'][0];
        $this->assertEquals(1, $item['contracts_found']);
        $this->assertEquals(5000.00, $item['unit_price']); // Only from the sell contract
    }

    public function test_appraise_buy_filter_only_includes_buy_contracts(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        // Sell contract: items are included (is_included=true)
        $this->createSingleItemContract(100001, 34, 100, 500000.00, true);

        // Buy contract: items are NOT included (is_included=false)
        $this->createSingleItemContract(100002, 34, 50, 200000.00, false);

        $result = $this->service->appraise("Tritanium\n", 'buy');

        $item = $result['items'][0];
        $this->assertEquals(1, $item['contracts_found']);
        $this->assertEquals(4000.00, $item['unit_price']); // Only from the buy contract
    }

    public function test_appraise_all_filter_includes_both(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        // Sell contract
        $this->createSingleItemContract(100001, 34, 100, 500000.00, true);

        // Buy contract
        $this->createSingleItemContract(100002, 34, 50, 200000.00, false);

        $result = $this->service->appraise("Tritanium\n", 'all');

        $item = $result['items'][0];
        $this->assertEquals(2, $item['contracts_found']);
    }

    public function test_appraise_lowest_method_uses_cheapest_contract(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'published' => true,
        ]);

        // Two single-item Tritanium contracts: 5000/unit and 6000/unit
        $this->createSingleItemContract(100001, 34, 100, 500000.00);  // 5000 per unit
        $this->createSingleItemContract(100002, 34, 200, 1200000.00); // 6000 per unit

        $result = $this->service->appraise("Tritanium\nTritanium\n", 'all', 'lowest');

        $item = $result['items'][0];
        $this->assertEquals(2, $item['contracts_found']);
        $this->assertEquals(5000.00, $item['unit_price']);
        $this->assertEquals(10000.00, $item['line_total']);
        $this->assertEquals(10000.00, $result['total_value']);
    }

    private function createSingleItemContract(int $contractId, int $typeId, int $quantity, float $price, bool $isIncluded = true): void
    {
        $this->createContract($contractId, $price);
        $this->createItem($contractId, $typeId, $quantity, $isIncluded);
    }

    private function createContract(int $contractId, float $price): void
    {
        $generationId = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        DB::table('public_contract_snapshot_contracts')->insert([
            'generation_id' => $generationId,
            'contract_id' => $contractId,
            'region_id' => 10000002,
            'type' => 'item_exchange',
            'price' => $price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createItem(int $contractId, int $typeId, int $quantity, bool $isIncluded): void
    {
        $generationId = (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        DB::table('public_contract_snapshot_items')->insert([
            'generation_id' => $generationId,
            'contract_id' => $contractId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'is_included' => $isIncluded,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
