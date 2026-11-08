<?php

namespace Tests\Feature\ApiV1\Market;

use App\Models\PublicContract;
use App\Models\PublicContractItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Market\MarketFixtures;
use Tests\TestCase;

class ContractsTest extends TestCase
{
    use MarketFixtures;
    use RefreshDatabase;

    private function contract(int $id, string $type, string $title, string $issued = '2026-10-20 10:00:00'): PublicContract
    {
        return PublicContract::create([
            'contract_id' => $id,
            'region_id' => 10000002,
            'type' => $type,
            'title' => $title,
            'issuer_id' => 9,
            'price' => 1500.5,
            'volume' => 10,
            'date_issued' => $issued,
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/market/contracts')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/market/contracts/1')->assertStatus(401);
    }

    public function test_lists_newest_first_with_stats_and_item_counts(): void
    {
        $this->contract(1, 'item_exchange', 'Old', '2026-10-19 10:00:00');
        $this->contract(2, 'courier', 'New', '2026-10-21 10:00:00');
        PublicContractItem::create(['contract_id' => 1, 'type_id' => 34, 'quantity' => 5, 'is_included' => true]);

        $response = $this->actingAs($this->marketUser())->getJson('/api/v1/market/contracts')->assertOk();

        $response->assertJsonPath('data.0.contract_id', 2)
            ->assertJsonPath('data.1.items_count', 1)
            ->assertJsonPath('data.1.price', 1500.5)
            ->assertJsonPath('meta.stats', ['total' => 2, 'item_exchange' => 1, 'auction' => 0, 'courier' => 1])
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.freshness.source', 'public_contracts');
    }

    public function test_filters_by_type_and_title_but_stats_ignore_filters(): void
    {
        $this->contract(1, 'item_exchange', 'Blueprint Pack');
        $this->contract(2, 'item_exchange', 'Minerals Lot');
        $this->contract(3, 'courier', 'Blueprint run');
        $user = $this->marketUser();

        $this->actingAs($user)->getJson('/api/v1/market/contracts?type=item_exchange&search=Blueprint')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contract_id', 1)
            ->assertJsonPath('meta.stats.total', 3);

        $this->actingAs($user)->getJson('/api/v1/market/contracts?type=bogus')->assertStatus(422);
    }

    public function test_detail_resolves_type_names_and_hides_zero_amounts(): void
    {
        $this->seedType(34, 'Tritanium');
        $this->contract(7, 'item_exchange', '');
        PublicContractItem::create(['contract_id' => 7, 'type_id' => 34, 'quantity' => 100, 'is_included' => true]);
        PublicContractItem::create([
            'contract_id' => 7, 'type_id' => 999, 'quantity' => 1, 'is_included' => false,
            'is_blueprint_copy' => true, 'material_efficiency' => 10, 'time_efficiency' => 20, 'runs' => 5,
        ]);

        $response = $this->actingAs($this->marketUser())->getJson('/api/v1/market/contracts/7')->assertOk();

        $response->assertJsonPath('data.title', null)
            ->assertJsonPath('data.buyout', null)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.items.0.type_name', 'Tritanium')
            ->assertJsonPath('data.items.0.runs', null)
            ->assertJsonPath('data.items.1.type_name', 'Unknown Type #999')
            ->assertJsonPath('data.items.1.is_blueprint_copy', true)
            ->assertJsonPath('data.items.1.runs', 5);
    }

    public function test_unknown_contract_is_404(): void
    {
        $this->actingAs($this->marketUser())->getJson('/api/v1/market/contracts/404')
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }
}
