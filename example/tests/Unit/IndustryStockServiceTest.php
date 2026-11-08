<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\EveType;
use App\Models\IndustryStockItem;
use App\Models\User;
use App\Services\AssetService;
use App\Services\IndustryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndustryStockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_search_types_returns_published_types_with_icon(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        EveType::create(['type_id' => 35, 'name' => 'Pyerite', 'published' => true]);
        EveType::create(['type_id' => 36, 'name' => 'Unpublished Trit', 'published' => false]);

        $assetService = Mockery::mock(AssetService::class);
        $service = new IndustryStockService($assetService);

        $results = $service->searchTypes('tri');

        $this->assertCount(1, $results);
        $this->assertSame(34, $results[0]['type_id']);
        $this->assertSame('Tritanium', $results[0]['name']);
        $this->assertArrayHasKey('icon_url', $results[0]);
        $this->assertNotEmpty($results[0]['icon_url']);
    }

    public function test_search_types_empty_query_returns_empty(): void
    {
        $assetService = Mockery::mock(AssetService::class);
        $service = new IndustryStockService($assetService);

        $this->assertSame([], $service->searchTypes(''));
        $this->assertSame([], $service->searchTypes('   '));
    }

    public function test_get_stock_report_sums_only_corporate_assets_and_computes_shortfall(): void
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u@test.com', 'is_active' => true,
            'is_stock_manager_enabled' => true,
        ]);
        Character::create([
            'character_id' => 1,
            'user_id' => $user->id,
            'name' => 'Main',
            'corporation_id' => 9000,
            'is_main' => true,
            'is_director_ceo' => true,
            'scopes' => ['esi-assets.read_corporation_assets.v1'],
        ]);

        IndustryStockItem::create([
            'user_id' => $user->id,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'desired_quantity' => 1000,
        ]);
        IndustryStockItem::create([
            'user_id' => $user->id,
            'type_id' => 35,
            'type_name' => 'Pyerite',
            'desired_quantity' => 500,
        ]);

        $assetService = Mockery::mock(AssetService::class);
        $assetService->shouldReceive('getCorporationAssetsWarning')
            ->with($user->id)
            ->andReturn(null);
        $assetService->shouldReceive('getAssetsForUser')
            ->with($user->id)
            ->andReturn([
                // Corporate: should count
                ['type_id' => 34, 'quantity' => 200, 'owner_type' => 'corporate'],
                ['type_id' => 34, 'quantity' => 300, 'owner_type' => 'corporate'],
                // Personal: should NOT count
                ['type_id' => 34, 'quantity' => 9999, 'owner_type' => 'personal'],
                // Different type
                ['type_id' => 35, 'quantity' => 500, 'owner_type' => 'corporate'],
                // Unrelated corp type
                ['type_id' => 99, 'quantity' => 42, 'owner_type' => 'corporate'],
            ]);

        $service = new IndustryStockService($assetService);
        $report = $service->getStockReport($user->id);

        $this->assertNull($report['warning']);
        $this->assertCount(2, $report['items']);

        $byType = collect($report['items'])->keyBy('type_id');

        $this->assertSame(500, $byType[34]['on_hand']);  // 200+300 only
        $this->assertSame(1000, $byType[34]['desired']);
        $this->assertSame(500, $byType[34]['shortfall']);

        $this->assertSame(500, $byType[35]['on_hand']);
        $this->assertSame(500, $byType[35]['desired']);
        $this->assertSame(0, $byType[35]['shortfall']);
    }

    public function test_get_stock_report_returns_warning_without_calling_assets(): void
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u2@test.com', 'is_active' => true,
            'is_stock_manager_enabled' => true,
        ]);
        IndustryStockItem::create([
            'user_id' => $user->id,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'desired_quantity' => 100,
        ]);

        $assetService = Mockery::mock(AssetService::class);
        $assetService->shouldReceive('getCorporationAssetsWarning')
            ->with($user->id)
            ->andReturn('Scope missing');
        $assetService->shouldNotReceive('getAssetsForUser');

        $service = new IndustryStockService($assetService);
        $report = $service->getStockReport($user->id);

        $this->assertSame('Scope missing', $report['warning']);
        $this->assertCount(1, $report['items']);
        $this->assertSame(0, $report['items'][0]['on_hand']);
        $this->assertSame(100, $report['items'][0]['shortfall']);
    }
}
