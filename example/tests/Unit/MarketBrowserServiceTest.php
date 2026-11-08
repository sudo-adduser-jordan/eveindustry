<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\MarketGroup;
use App\Services\MarketBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketBrowserServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketBrowserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketBrowserService;
    }

    public function test_get_root_groups_returns_top_level_groups(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 9,
            'name' => 'Ship Equipment',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        $roots = $this->service->getRootGroups();

        $this->assertCount(2, $roots);
        $this->assertEquals('Ship Equipment', $roots[0]['name']);
        $this->assertEquals('Ships', $roots[1]['name']);
    }

    public function test_get_root_groups_returns_empty_when_no_data(): void
    {
        $roots = $this->service->getRootGroups();
        $this->assertEmpty($roots);
    }

    public function test_get_group_contents_returns_children_and_items(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);
        MarketGroup::create([
            'market_group_id' => 101,
            'name' => 'Destroyers',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        $contents = $this->service->getGroupContents(2);

        $this->assertNotNull($contents['group']);
        $this->assertEquals('Ships', $contents['group']['name']);
        $this->assertCount(2, $contents['children']);
        $this->assertEmpty($contents['items']);
    }

    public function test_get_group_contents_returns_items_when_has_types(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create([
            'type_id' => 587,
            'name' => 'Rifter',
            'market_group_id' => 100,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 603,
            'name' => 'Merlin',
            'market_group_id' => 100,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 9999,
            'name' => 'Unpublished Ship',
            'market_group_id' => 100,
            'published' => false,
        ]);

        $contents = $this->service->getGroupContents(100);

        $this->assertNotNull($contents['group']);
        $this->assertEquals('Frigates', $contents['group']['name']);
        $this->assertCount(2, $contents['items']);
        $this->assertEquals('Merlin', $contents['items'][0]['name']);
        $this->assertEquals('Rifter', $contents['items'][1]['name']);
    }

    public function test_get_group_contents_returns_null_group_for_nonexistent_id(): void
    {
        $contents = $this->service->getGroupContents(99999);

        $this->assertNull($contents['group']);
        $this->assertEmpty($contents['children']);
        $this->assertEmpty($contents['items']);
    }

    public function test_get_group_contents_includes_breadcrumbs(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        $contents = $this->service->getGroupContents(100);

        $this->assertCount(2, $contents['breadcrumbs']);
        $this->assertEquals('Ships', $contents['breadcrumbs'][0]['name']);
        $this->assertEquals('Frigates', $contents['breadcrumbs'][1]['name']);
    }

    public function test_get_group_contents_child_count_is_correct(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);
        MarketGroup::create([
            'market_group_id' => 101,
            'name' => 'Destroyers',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        $contents = $this->service->getGroupContents(2);
        $this->assertEquals(0, $contents['children'][0]['child_count']);
    }

    public function test_search_items_returns_matching_types(): void
    {
        EveType::create([
            'type_id' => 587,
            'name' => 'Rifter',
            'market_group_id' => 100,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 603,
            'name' => 'Merlin',
            'market_group_id' => 100,
            'published' => true,
        ]);

        $results = $this->service->searchItems('rift');

        $this->assertCount(1, $results);
        $this->assertEquals('Rifter', $results[0]['name']);
        $this->assertEquals(587, $results[0]['type_id']);
    }

    public function test_search_items_returns_empty_for_short_query(): void
    {
        $results = $this->service->searchItems('a');
        $this->assertEmpty($results);
    }

    public function test_search_items_excludes_unpublished_and_no_market_group(): void
    {
        EveType::create([
            'type_id' => 587,
            'name' => 'Rifter',
            'market_group_id' => 100,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 9999,
            'name' => 'Rifter Test',
            'market_group_id' => null,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 8888,
            'name' => 'Rifter Hidden',
            'market_group_id' => 100,
            'published' => false,
        ]);

        $results = $this->service->searchItems('rifter');
        $this->assertCount(1, $results);
        $this->assertEquals('Rifter', $results[0]['name']);
    }

    public function test_get_full_tree_returns_nested_hierarchy(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 9,
            'name' => 'Ship Equipment',
            'parent_group_id' => null,
            'has_types' => true,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);
        MarketGroup::create([
            'market_group_id' => 101,
            'name' => 'Destroyers',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        // Create manufacturable items in Frigates and Ship Equipment
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 603, 'name' => 'Merlin', 'market_group_id' => 101, 'published' => true]);
        EveType::create(['type_id' => 700, 'name' => 'Laser', 'market_group_id' => 9, 'published' => true]);
        BlueprintActivity::create([
            'blueprint_type_id' => 1587,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 1603,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 603, 'quantity' => 1]],
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 1700,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 700, 'quantity' => 1]],
        ]);

        $tree = $this->service->getFullTree();

        // Two root groups (sorted by name)
        $this->assertCount(2, $tree);
        $this->assertEquals('Ship Equipment', $tree[0]['name']);
        $this->assertEquals('Ships', $tree[1]['name']);

        // Ships has two children
        $this->assertCount(2, $tree[1]['children']);
        $this->assertEquals('Destroyers', $tree[1]['children'][0]['name']);
        $this->assertEquals('Frigates', $tree[1]['children'][1]['name']);
        $this->assertTrue($tree[1]['children'][1]['has_types']);

        // Ship Equipment has no children
        $this->assertCount(0, $tree[0]['children']);
    }

    public function test_get_full_tree_returns_empty_when_no_groups(): void
    {
        $tree = $this->service->getFullTree();
        $this->assertEmpty($tree);
    }

    public function test_get_full_tree_does_not_include_items(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => null,
            'has_types' => true,
        ]);

        EveType::create([
            'type_id' => 587,
            'name' => 'Rifter',
            'market_group_id' => 100,
            'published' => true,
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 1587,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);

        $tree = $this->service->getFullTree();

        $this->assertCount(1, $tree);
        $this->assertEquals('Frigates', $tree[0]['name']);
        $this->assertArrayNotHasKey('items', $tree[0]);
        $this->assertArrayHasKey('children', $tree[0]);
    }

    public function test_get_full_tree_excludes_groups_without_manufacturable_items(): void
    {
        // Ships → Frigates (has a manufacturable item)
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        // Apparel (no blueprints at all)
        MarketGroup::create([
            'market_group_id' => 1396,
            'name' => 'Apparel',
            'parent_group_id' => null,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 50000, 'name' => 'Fancy Hat', 'market_group_id' => 1396, 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1587,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);

        $tree = $this->service->getFullTree();

        // Only Ships should appear; Apparel has no manufacturable items
        $this->assertCount(1, $tree);
        $this->assertEquals('Ships', $tree[0]['name']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertEquals('Frigates', $tree[0]['children'][0]['name']);
    }

    public function test_get_full_tree_excludes_invented_blueprint_products(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        // T1 group with blueprint
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);
        // T2 group — only has items from invented blueprints
        MarketGroup::create([
            'market_group_id' => 200,
            'name' => 'T2 Only',
            'parent_group_id' => null,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 800, 'name' => 'T2 Ship', 'market_group_id' => 200, 'published' => true]);

        // T1 blueprint for Rifter
        BlueprintActivity::create([
            'blueprint_type_id' => 1587,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);

        // Invented blueprint (T2): invention produces this blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => 2000,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 600,
            'materials' => [],
            'products' => [['type_id' => 2001, 'quantity' => 1]],
        ]);
        // T2 blueprint manufactures the T2 Ship
        BlueprintActivity::create([
            'blueprint_type_id' => 2001,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 800, 'quantity' => 1]],
        ]);

        $tree = $this->service->getFullTree();

        // Only Ships/Frigates should appear; T2 Only should be excluded
        $this->assertCount(1, $tree);
        $this->assertEquals('Ships', $tree[0]['name']);
    }

    public function test_get_full_tree_includes_sibling_groups_of_manufactured_leaves(): void
    {
        // Ships → Frigates (parent of leaves)
        //       → Standard Frigates (has manufactured item)
        //       → Faction Frigates (no manufactured items, but sibling)
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 50,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Standard Frigates',
            'parent_group_id' => 50,
            'has_types' => true,
        ]);
        MarketGroup::create([
            'market_group_id' => 101,
            'name' => 'Faction Frigates',
            'parent_group_id' => 50,
            'has_types' => true,
        ]);

        // Apparel (root group, no manufactured items anywhere in tree)
        MarketGroup::create([
            'market_group_id' => 1396,
            'name' => 'Apparel',
            'parent_group_id' => null,
            'has_types' => true,
        ]);

        // Only Standard Frigates has a manufactured item;
        // Navy Slicer has no blueprint but should appear via sibling inclusion
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 17703, 'name' => 'Navy Slicer', 'market_group_id' => 101, 'published' => true]);
        EveType::create(['type_id' => 50000, 'name' => 'Fancy Hat', 'market_group_id' => 1396, 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1587,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);

        $tree = $this->service->getFullTree();

        // Ships should appear; Apparel (root with no manufactured descendants) should not
        $this->assertCount(1, $tree);
        $this->assertEquals('Ships', $tree[0]['name']);
        $treeNames = array_column($tree, 'name');
        $this->assertNotContains('Apparel', $treeNames);

        // Frigates should have BOTH children (sibling inclusion)
        $frigates = $tree[0]['children'][0];
        $this->assertEquals('Frigates', $frigates['name']);
        $this->assertCount(2, $frigates['children']);
        $this->assertEquals('Faction Frigates', $frigates['children'][0]['name']);
        $this->assertEquals('Standard Frigates', $frigates['children'][1]['name']);
    }

    // ── Meta group exclusion tests ────────────────────────────────────

    public function test_get_group_contents_excludes_faction_items(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_I]);
        EveType::create(['type_id' => 17703, 'name' => 'Dramiel', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_FACTION]);
        EveType::create(['type_id' => 22228, 'name' => 'Wolf', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_II]);

        // Exclude faction items
        $result = $this->service->getGroupContents(100, [EveType::META_GROUP_FACTION]);
        $itemNames = array_column($result['items'], 'name');

        $this->assertContains('Rifter', $itemNames);
        $this->assertNotContains('Dramiel', $itemNames);
        $this->assertContains('Wolf', $itemNames);
    }

    public function test_get_group_contents_excludes_t1_and_faction_items(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_I]);
        EveType::create(['type_id' => 17703, 'name' => 'Dramiel', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_FACTION]);
        EveType::create(['type_id' => 22228, 'name' => 'Wolf', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_II]);

        // Exclude both T1 and Faction items
        $result = $this->service->getGroupContents(100, [EveType::META_GROUP_TECH_I, EveType::META_GROUP_FACTION]);
        $itemNames = array_column($result['items'], 'name');

        $this->assertNotContains('Rifter', $itemNames);
        $this->assertNotContains('Dramiel', $itemNames);
        $this->assertContains('Wolf', $itemNames);
    }

    public function test_get_group_contents_includes_null_meta_group_when_excluding(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Items',
            'has_types' => true,
        ]);

        // Item with no meta_group_id (should be included when excluding Faction)
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => null]);
        EveType::create(['type_id' => 17703, 'name' => 'Dramiel', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_FACTION]);

        $result = $this->service->getGroupContents(100, [EveType::META_GROUP_FACTION]);
        $itemNames = array_column($result['items'], 'name');

        $this->assertContains('Tritanium', $itemNames);
        $this->assertNotContains('Dramiel', $itemNames);
    }

    public function test_search_items_excludes_meta_groups(): void
    {
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_I]);
        EveType::create(['type_id' => 17703, 'name' => 'Faction Rifter', 'market_group_id' => 100, 'published' => true, 'meta_group_id' => EveType::META_GROUP_FACTION]);

        // Without exclusion - both match "rift"
        $result = $this->service->searchItems('rift');
        $this->assertCount(2, $result);

        // Exclude Faction
        $result = $this->service->searchItems('rift', 50, [EveType::META_GROUP_FACTION]);
        $this->assertCount(1, $result);
        $this->assertEquals('Rifter', $result[0]['name']);
    }
}
