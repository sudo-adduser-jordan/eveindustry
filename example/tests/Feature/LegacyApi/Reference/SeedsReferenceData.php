<?php

namespace Tests\Feature\LegacyApi\Reference;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvCategory;
use App\Models\InvGroup;
use App\Models\MarketGroup;
use App\Models\SdeSolarSystem;
use App\Models\SdeStation;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Small SDE fixture shared by the reference-group characterisation tests
 * (old /api routes) and the /api/v1 reference tests.
 *
 * Every outside HTTP call (ESI, Fuzzwork) answers 404 unless a test fakes
 * something more specific first.
 */
trait SeedsReferenceData
{
    protected function blockOutsideHttp(array $fakes = []): void
    {
        // Start from a clean factory so a later call replaces earlier stubs.
        Http::swap(new Factory(app('events')));
        Http::fake($fakes + ['*' => Http::response(null, 404)]);
    }

    protected function makeUser(): User
    {
        return User::create([
            'name' => 'Pilot',
            'email' => 'pilot'.uniqid().'@test.com',
            'is_active' => true,
        ]);
    }

    protected function seedTypes(): void
    {
        $types = [
            [34, 'Tritanium', 18, 18, null, true],
            [587, 'Rifter', 25, 64, 1, true],
            [691, 'Rifter Blueprint', 105, 2, null, true],
            [11371, 'Wolf', 324, 64, 2, true],
            [11372, 'Wolf Blueprint', 105, 2, null, true],
            [11373, 'Rifter Navy Issue', 25, 64, 4, true],
            [20172, 'Datacore - Electromagnetic Physics', 333, 1880, null, true],
            [46166, 'Caesarium Cadmide Reaction Formula', 1888, 2, null, true],
            [16663, 'Caesarium Cadmide', 429, 500, null, true],
            [99999, 'Unpublished Rifter Blueprint', 105, null, null, false],
        ];

        foreach ($types as [$id, $name, $group, $marketGroup, $metaGroup, $published]) {
            EveType::create([
                'type_id' => $id,
                'name' => $name,
                'description' => "{$name} description",
                'group_id' => $group,
                'market_group_id' => $marketGroup,
                'meta_group_id' => $metaGroup,
                'volume' => 27289.5,
                'packaged_volume' => 2500.5,
                'mass' => 1067000.5,
                'capacity' => 140.5,
                'portion_size' => 1,
                'icon_id' => 7,
                'graphic_id' => 8,
                'published' => $published,
            ]);
        }
    }

    protected function seedBlueprints(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 691,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 6000,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 32000]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 691,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 63900,
            'materials' => [['type_id' => 20172, 'name' => 'Datacore - Electromagnetic Physics', 'quantity' => 2]],
            'products' => [['type_id' => 11372, 'name' => 'Wolf Blueprint', 'quantity' => 1, 'probability' => 0.3]],
            'skills' => [['type_id' => 21791, 'level' => 1], ['type_id' => 11433, 'level' => 1]],
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 11372,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 12000,
            'materials' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [['type_id' => 11371, 'name' => 'Wolf', 'quantity' => 1]],
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 10800,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 16663, 'name' => 'Caesarium Cadmide', 'quantity' => 200]],
        ]);

        InvCategory::create(['category_id' => 6, 'name' => 'Ship', 'published' => true]);
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
    }

    protected function seedSystems(): void
    {
        $systems = [
            [30000142, 'Jita', 10000002, 0.9459],
            [30000144, 'Perimeter', 10000002, 0.9568],
            [30002187, 'Amarr', 10000043, 1.0],
            [30045349, 'Itamo', 10000016, 0.6489],
        ];

        foreach ($systems as [$id, $name, $region, $security]) {
            SdeSolarSystem::create([
                'solar_system_id' => $id,
                'solar_system_name' => $name,
                'constellation_id' => 20000020,
                'region_id' => $region,
                'security' => $security,
            ]);
        }
    }

    protected function seedStations(): void
    {
        SdeStation::create([
            'station_id' => 60003760,
            'station_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'solar_system_id' => 30000142,
            'region_id' => 10000002,
            'security' => 0.9459,
        ]);
        SdeStation::create([
            'station_id' => 60003466,
            'station_name' => 'Jita IV - Moon 1 - Ishukone Corporation Factory',
            'solar_system_id' => 30000142,
            'region_id' => 10000002,
            'security' => 0.9459,
        ]);
        SdeStation::create([
            'station_id' => 60008494,
            'station_name' => 'Amarr VIII (Oris) - Emperor Family Academy',
            'solar_system_id' => 30002187,
            'region_id' => 10000043,
            'security' => 1.0,
        ]);
    }

    protected function seedMarketGroups(): void
    {
        $groups = [
            [4, 'Ships', null, false],
            [1361, 'Frigates', 4, false],
            [64, 'Standard Frigates', 1361, true],
            [2, 'Blueprints', null, false],
            [18, 'Minerals', 2000, true],
            [2000, 'Materials', null, false],
        ];

        foreach ($groups as [$id, $name, $parent, $hasTypes]) {
            MarketGroup::create([
                'market_group_id' => $id,
                'name' => $name,
                'description' => "{$name} group",
                'parent_group_id' => $parent,
                'has_types' => $hasTypes,
            ]);
        }
    }

    /** ESI /industry/systems/ payload for Jita. */
    protected function costIndexPayload(): array
    {
        return [
            [
                'solar_system_id' => 30000142,
                'cost_indices' => [
                    ['activity' => 'manufacturing', 'cost_index' => 0.0512],
                    ['activity' => 'researching_time_efficiency', 'cost_index' => 0.01],
                    ['activity' => 'researching_material_efficiency', 'cost_index' => 0.02],
                    ['activity' => 'copying', 'cost_index' => 0.03],
                    ['activity' => 'invention', 'cost_index' => 0.0634],
                    ['activity' => 'reaction', 'cost_index' => 0.0221],
                ],
            ],
        ];
    }
}
