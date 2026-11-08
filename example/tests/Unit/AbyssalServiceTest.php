<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\EveType;
use App\Models\User;
use App\Services\AbyssalService;
use App\Services\AssetService;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AbyssalServiceTest extends TestCase
{
    use RefreshDatabase;

    private AbyssalService $service;

    private $esiMock;

    private $assetServiceMock;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);

        $this->esiMock = Mockery::mock(EsiService::class);
        $this->assetServiceMock = Mockery::mock(AssetService::class);
        $this->service = new AbyssalService($this->esiMock, $this->assetServiceMock);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
        ]);
    }

    private function makeCharacter(array $overrides = []): Character
    {
        return Character::create(array_merge([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Test Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-assets.read_assets.v1'],
        ], $overrides));
    }

    public function test_abyssal_items_detect_changed_attributes(): void
    {
        $character = $this->makeCharacter();

        // Create an abyssal type in the DB
        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Warp Disruptor',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        // Asset data for a single abyssal item
        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99001,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Warp Disruptor',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4',
                ],
            ]);

        // Dynamic item endpoint returns mutated attributes + source_type_id
        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->with(47740, 99001, Mockery::type(Character::class))
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 26000.0],   // optimal range (mutated)
                    ['attribute_id' => 68, 'value' => 0.95],      // activation cost (mutated)
                ],
            ]);

        // Base type dogma attributes from ESI /universe/types/{source_type_id}/
        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 24000.0],   // optimal range (base)
                    ['attribute_id' => 68, 'value' => 1.0],       // activation cost (base)
                ],
            ]);

        // Attribute metadata
        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(54)
            ->andReturn([
                'attribute_id' => 54,
                'name' => 'optimalRange',
                'display_name' => 'Optimal Range',
                'high_is_good' => true,
                'unit_id' => 117,
            ]);

        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(68)
            ->andReturn([
                'attribute_id' => 68,
                'name' => 'activationCost',
                'display_name' => 'Activation Cost',
                'high_is_good' => false,
                'unit_id' => null,
            ]);

        // Source type name resolution
        EveType::create([
            'type_id' => 3242,
            'name' => 'Warp Disruptor II',
            'group_id' => 1,
            'published' => true,
        ]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);

        $this->assertCount(1, $result, 'Expected one source type group');

        $group = $result[0];
        $this->assertEquals(3242, $group['source_type_id']);
        $this->assertEquals('Warp Disruptor II', $group['source_type_name']);
        $this->assertCount(1, $group['items']);

        $item = $group['items'][0];
        $this->assertEquals(99001, $item['item_id']);
        $this->assertNotEmpty($item['attributes'], 'Attributes should not be empty when stats differ from base');

        // Check that both changed attributes are present
        $attrIds = array_column($item['attributes'], 'attribute_id');
        $this->assertContains(54, $attrIds, 'Optimal range should be detected as changed');
        $this->assertContains(68, $attrIds, 'Activation cost should be detected as changed');

        // Verify the percentage change values
        $attrMap = [];
        foreach ($item['attributes'] as $attr) {
            $attrMap[$attr['attribute_id']] = $attr;
        }

        // Optimal range: 26000 vs 24000 = +8.33% (good, since high_is_good=true)
        $this->assertEqualsWithDelta(8.333, $attrMap[54]['pct_change'], 0.01);
        $this->assertTrue($attrMap[54]['is_good']);

        // Activation cost: 0.95 vs 1.0 = -5% (good, since high_is_good=false)
        $this->assertEqualsWithDelta(-5.0, $attrMap[68]['pct_change'], 0.01);
        $this->assertTrue($attrMap[68]['is_good']);

        // Score should be positive (both changes are good)
        $this->assertGreaterThan(0, $item['score']);
    }

    public function test_unchanged_attributes_are_excluded(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Warp Disruptor',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99002,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Warp Disruptor',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita',
                ],
            ]);

        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 24000.0],   // unchanged
                    ['attribute_id' => 68, 'value' => 0.90],      // changed
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 24000.0],   // same as dynamic
                    ['attribute_id' => 68, 'value' => 1.0],       // different from dynamic
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(68)
            ->andReturn([
                'attribute_id' => 68,
                'name' => 'activationCost',
                'display_name' => 'Activation Cost',
                'high_is_good' => false,
                'unit_id' => null,
            ]);

        EveType::create([
            'type_id' => 3242,
            'name' => 'Warp Disruptor II',
            'group_id' => 1,
            'published' => true,
        ]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);

        $this->assertCount(1, $result);
        $item = $result[0]['items'][0];

        // Only the changed attribute should be present
        $attrIds = array_column($item['attributes'], 'attribute_id');
        $this->assertContains(68, $attrIds, 'Changed attribute should be present');
        $this->assertNotContains(54, $attrIds, 'Unchanged attribute should be excluded');
    }

    public function test_null_base_attributes_produces_no_attribute_changes(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Warp Disruptor',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99003,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Warp Disruptor',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita',
                ],
            ]);

        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 26000.0],
                ],
            ]);

        // Simulate the old bug: ESI returns null for base attributes
        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn(null);

        EveType::create([
            'type_id' => 3242,
            'name' => 'Warp Disruptor II',
            'group_id' => 1,
            'published' => true,
        ]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);

        $this->assertCount(1, $result);
        $item = $result[0]['items'][0];
        $this->assertEmpty($item['attributes'], 'No attributes should be detected when base data is null');
        $this->assertEquals(0.0, $item['score']);
    }

    public function test_format_attribute_value_with_various_units(): void
    {
        // Percentage unit (105)
        $this->assertEquals('50.00%', AbyssalService::formatAttributeValue(0.5, 105));

        // Multiplier unit (109)
        $this->assertEquals('1.2500x', AbyssalService::formatAttributeValue(1.25, 109));

        // Milliseconds unit (115) → displayed as seconds
        $this->assertEquals('2.50s', AbyssalService::formatAttributeValue(2500.0, 115));

        // Meters unit (117) - below 1000
        $this->assertEquals('500.00 m', AbyssalService::formatAttributeValue(500.0, 117));

        // Meters unit (117) - above 1000 → displayed as km
        $this->assertEquals('24.00 km', AbyssalService::formatAttributeValue(24000.0, 117));
    }

    public function test_format_attribute_change_with_various_units(): void
    {
        // Milliseconds (115): diff = 2000ms → "+2.00s"
        $this->assertEquals('+2.00s', AbyssalService::formatAttributeChange(4500.0, 2500.0, 115));

        // Milliseconds negative: diff = -1000ms → "-1.00s"
        $this->assertEquals('-1.00s', AbyssalService::formatAttributeChange(2500.0, 3500.0, 115));

        // Meters (117): diff = 2000m → "+2.00 km"
        $this->assertEquals('+2.00 km', AbyssalService::formatAttributeChange(26000.0, 24000.0, 117));

        // Meters (117): small diff < 1000 → "+500.00 m"
        $this->assertEquals('+500.00 m', AbyssalService::formatAttributeChange(1000.0, 500.0, 117));

        // Meters negative: diff = -2000m → "-2.00 km"
        $this->assertEquals('-2.00 km', AbyssalService::formatAttributeChange(22000.0, 24000.0, 117));

        // Percentage (105): diff = 0.1 → "+10.00%"
        $this->assertEquals('+10.00%', AbyssalService::formatAttributeChange(0.6, 0.5, 105));

        // Percentage (105): negative diff → "-5.00%"
        $this->assertEquals('-5.00%', AbyssalService::formatAttributeChange(0.45, 0.5, 105));

        // m³ (120): positive diff → "+24.27 m³"
        $this->assertEquals('+24.27 m³', AbyssalService::formatAttributeChange(224.27, 200.0, 120));

        // Default unit: positive diff → "+0.05"
        $this->assertEquals('+0.05', AbyssalService::formatAttributeChange(1.05, 1.0, null));

        // Inverse Absolute Percent (101): base=0.3, value=0.25 → display change = (0.3-0.25)*100 = +5.00%
        $this->assertEquals('+5.00%', AbyssalService::formatAttributeChange(0.25, 0.3, 101));
    }

    public function test_mining_amount_attribute_overrides_unit_to_m3(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Mining Laser Upgrade',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99020,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Mining Laser Upgrade',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4',
                ],
            ]);

        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 114, 'value' => 250.0],  // mining amount (mutated)
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 114, 'value' => 200.0],  // mining amount (base)
                ],
            ]);

        // ESI returns unit_id 9 (Teraflops) — incorrect — for mining amount.
        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(114)
            ->andReturn([
                'attribute_id' => 114,
                'name' => 'miningAmount',
                'display_name' => 'Mining Amount',
                'high_is_good' => true,
                'unit_id' => 9, // wrong in ESI; should be 120 (m³)
            ]);

        EveType::create([
            'type_id' => 3242,
            'name' => 'Mining Laser Upgrade II',
            'group_id' => 1,
            'published' => true,
        ]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);

        $this->assertCount(1, $result);
        $item = $result[0]['items'][0];
        $this->assertNotEmpty($item['attributes']);

        $attrMap = array_column($item['attributes'], null, 'attribute_id');

        // unit_id should be corrected from 9 (tf) to 120 (m³)
        $this->assertEquals(120, $attrMap[114]['unit_id']);
        $this->assertEquals('250.00 m³', AbyssalService::formatAttributeValue($attrMap[114]['value'], $attrMap[114]['unit_id']));
    }

    public function test_effective_mining_speed_shown_when_both_attributes_changed(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Mining Laser Upgrade',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99021,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Mining Laser Upgrade',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4',
                ],
            ]);

        // Both mining amount (attr 114) and duration (attr 73) changed
        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 114, 'value' => 250.0],    // mining: 250 m³
                    ['attribute_id' => 73,  'value' => 60000.0],  // duration: 60s
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 114, 'value' => 200.0],   // base: 200 m³
                    ['attribute_id' => 73,  'value' => 60000.0], // base: 60s (unchanged)
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(114)
            ->andReturn([
                'attribute_id' => 114, 'name' => 'miningAmount',
                'display_name' => 'Mining Amount', 'high_is_good' => true, 'unit_id' => 9,
            ]);

        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(73)
            ->andReturn([
                'attribute_id' => 73, 'name' => 'duration',
                'display_name' => 'Activation time', 'high_is_good' => false, 'unit_id' => 115,
            ]);

        EveType::create(['type_id' => 3242, 'name' => 'Mining Laser Upgrade II', 'group_id' => 1, 'published' => true]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);
        $item = $result[0]['items'][0];

        $attrIds = array_column($item['attributes'], 'attribute_id');
        $this->assertContains('_effective_m3s', $attrIds, 'Effective m³/s should be appended');

        $effAttr = array_values(array_filter($item['attributes'], fn ($a) => $a['attribute_id'] === '_effective_m3s'))[0];
        // 250 m³ / 60s = 4.1667 m³/s; base = 200/60 = 3.3333
        $this->assertEqualsWithDelta(250.0 / 60.0, $effAttr['value'], 0.001);
        $this->assertEqualsWithDelta(200.0 / 60.0, $effAttr['base_value'], 0.001);
        $this->assertTrue($effAttr['is_good']);
    }

    public function test_effective_mining_speed_shown_when_only_duration_changed(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47741,
            'name' => 'Abyssal Mining Laser 2',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99022,
                    'type_id' => 47741,
                    'type_name' => 'Abyssal Mining Laser 2',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4',
                ],
            ]);

        // Only duration changed; mining amount unchanged
        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3243,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 73,  'value' => 50000.0],  // duration mutated: 50s
                    ['attribute_id' => 114, 'value' => 200.0],    // mining unchanged: 200 m³
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3243)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 73,  'value' => 60000.0],  // base: 60s
                    ['attribute_id' => 114, 'value' => 200.0],    // base: 200 m³ (same)
                ],
            ]);

        // duration attribute — called during changed-attr processing
        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(73)
            ->andReturn([
                'attribute_id' => 73, 'name' => 'duration',
                'display_name' => 'Activation time', 'high_is_good' => false, 'unit_id' => 115,
            ]);

        // miningAmount attribute — called during the fallback scan for unchanged attrs
        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(114)
            ->andReturn([
                'attribute_id' => 114, 'name' => 'miningAmount',
                'display_name' => 'Mining Amount', 'high_is_good' => true, 'unit_id' => 120,
            ]);

        EveType::create(['type_id' => 3243, 'name' => 'Mining Laser II', 'group_id' => 1, 'published' => true]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);
        $item = $result[0]['items'][0];

        $attrIds = array_column($item['attributes'], 'attribute_id');
        $this->assertContains('_effective_m3s', $attrIds,
            'Effective m³/s should appear even when only duration changed');

        $effAttr = array_values(array_filter($item['attributes'], fn ($a) => $a['attribute_id'] === '_effective_m3s'))[0];
        // mutated: 200 / 50 = 4 m³/s; base: 200 / 60 = 3.333 m³/s
        $this->assertEqualsWithDelta(200.0 / 50.0, $effAttr['value'], 0.001);
        $this->assertEqualsWithDelta(200.0 / 60.0, $effAttr['base_value'], 0.001);
        $this->assertTrue($effAttr['is_good']);
    }

    public function test_item_includes_location_and_container_name(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Warp Disruptor',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                // The container item (not abyssal — just a container)
                [
                    'item_id' => 88001,
                    'type_id' => 3293,   // Small Freight Container
                    'type_name' => 'Small Freight Container',
                    'name' => 'My Gear Box',   // custom name set by player
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                ],
                // The abyssal item inside the container
                [
                    'item_id' => 99030,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Warp Disruptor',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Small Freight Container in Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                    'parent_item_id' => 88001,
                ],
            ]);

        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 26000.0],
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 54, 'value' => 24000.0],
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(54)
            ->andReturn([
                'attribute_id' => 54, 'name' => 'optimalRange',
                'display_name' => 'Optimal Range', 'high_is_good' => true, 'unit_id' => 117,
            ]);

        EveType::create(['type_id' => 3242, 'name' => 'Warp Disruptor II', 'group_id' => 1, 'published' => true]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);

        $this->assertCount(1, $result);
        $item = $result[0]['items'][0];

        $this->assertEquals(
            'Small Freight Container in Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            $item['location_name']
        );
        // Container custom name resolved from the parent asset
        $this->assertEquals('My Gear Box', $item['container_name']);
    }

    public function test_item_has_null_container_name_when_not_in_container(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Warp Disruptor',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99031,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Warp Disruptor',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                    // no parent_item_id
                ],
            ]);

        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [['attribute_id' => 54, 'value' => 26000.0]],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [['attribute_id' => 54, 'value' => 24000.0]],
            ]);

        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(54)
            ->andReturn([
                'attribute_id' => 54, 'name' => 'optimalRange',
                'display_name' => 'Optimal Range', 'high_is_good' => true, 'unit_id' => 117,
            ]);

        EveType::create(['type_id' => 3242, 'name' => 'Warp Disruptor II', 'group_id' => 1, 'published' => true]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);
        $item = $result[0]['items'][0];

        $this->assertNull($item['container_name']);
        $this->assertEquals('Jita IV - Moon 4 - Caldari Navy Assembly Plant', $item['location_name']);
    }

    public function test_duration_attribute_displays_in_seconds_not_percentage(): void
    {
        $character = $this->makeCharacter();

        EveType::create([
            'type_id' => 47740,
            'name' => 'Abyssal Warp Disruptor',
            'group_id' => 1,
            'meta_group_id' => EveType::META_GROUP_ABYSSAL,
            'published' => true,
        ]);

        $this->assetServiceMock->shouldReceive('getAssetsForUser')
            ->once()
            ->with($this->user->id)
            ->andReturn([
                [
                    'item_id' => 99010,
                    'type_id' => 47740,
                    'type_name' => 'Abyssal Warp Disruptor',
                    'character_id' => 12345,
                    'character_name' => 'Test Char',
                    'location_name' => 'Jita IV - Moon 4',
                ],
            ]);

        $this->esiMock->shouldReceive('getDynamicItem')
            ->once()
            ->andReturn([
                'source_type_id' => 3242,
                'mutator_type_id' => 47800,
                'dogma_attributes' => [
                    ['attribute_id' => 73, 'value' => 8500.0],  // duration: 8.5s (mutated)
                ],
            ]);

        $this->esiMock->shouldReceive('getDogmaTypeAttributes')
            ->once()
            ->with(3242)
            ->andReturn([
                'dogma_attributes' => [
                    ['attribute_id' => 73, 'value' => 10000.0], // duration: 10s (base)
                ],
            ]);

        // ESI returns unit_id 101 (Inverse Absolute Percent) for the duration
        // attribute, but the values are actually in milliseconds.
        $this->esiMock->shouldReceive('getDogmaAttribute')
            ->with(73)
            ->andReturn([
                'attribute_id' => 73,
                'name' => 'duration',
                'display_name' => 'Activation time / duration',
                'high_is_good' => false,
                'unit_id' => 101,
            ]);

        EveType::create([
            'type_id' => 3242,
            'name' => 'Warp Disruptor II',
            'group_id' => 1,
            'published' => true,
        ]);

        $result = $this->service->getAbyssalItemsForUser($this->user->id);

        $this->assertCount(1, $result);
        $item = $result[0]['items'][0];
        $this->assertNotEmpty($item['attributes']);

        $attr = $item['attributes'][0];
        $this->assertEquals(73, $attr['attribute_id']);
        // The unit_id should be overridden to 115 (Milliseconds) so the view
        // formats the value as seconds, not as a percentage.
        $this->assertEquals(115, $attr['unit_id']);

        // Verify that formatting produces seconds, not percentage
        $valueFormatted = AbyssalService::formatAttributeValue($attr['value'], $attr['unit_id']);
        $changeFormatted = AbyssalService::formatAttributeChange($attr['value'], $attr['base_value'], $attr['unit_id']);
        $this->assertEquals('8.50s', $valueFormatted);
        $this->assertEquals('-1.50s', $changeFormatted);
    }
}
