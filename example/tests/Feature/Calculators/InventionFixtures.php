<?php

namespace Tests\Feature\Calculators;

use App\Services\InventionPlannerService;
use Mockery;

/**
 * Seeding and request builders shared by the old-route characterisation test and the /api/v1 test (no test methods, so neither suite re-runs the other).
 */
trait InventionFixtures
{
    /**
     * Wolf-like chain whose T2 BOM uses the seeded buildable component
     * (77003) and composite (77005, a reaction) plus plain Tritanium.
     */
    protected function mockInventionChain(): void
    {
        $planner = Mockery::mock(app(InventionPlannerService::class));
        $planner->shouldReceive('resolveInventionChain')->andReturnUsing(fn (int $typeId) => $typeId !== 11371 ? null : [
            't2_item_type_id' => 11371,
            't2_blueprint_type_id' => 11372,
            't2_manufacturing' => [
                'time' => 3600,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 400],
                    ['type_id' => 77003, 'name' => 'Test Component', 'quantity' => 6],
                    ['type_id' => 77005, 'name' => 'Test Composite', 'quantity' => 15],
                ],
                'products' => [['type_id' => 11371, 'name' => 'Wolf', 'quantity' => 1]],
            ],
            't1_item_type_id' => 587,
            't1_item_name' => 'Rifter',
            't1_blueprint_type_id' => 691,
            't1_manufacturing' => ['materials' => [], 'products' => []],
            'datacores' => [
                ['type_id' => 20171, 'name' => 'Datacore - Mechanical Engineering', 'quantity' => 2],
                ['type_id' => 20424, 'name' => 'Datacore - Minmatar Starship Engineering', 'quantity' => 3],
            ],
            'invention_time' => 1200,
            'base_probability' => 0.34,
            'base_runs' => 1,
            'science_skill_ids' => [],
            'encryption_skill_id' => null,
            'encryption_skill_name' => null,
            'science1_skill_id' => null,
            'science1_skill_name' => null,
            'science2_skill_id' => null,
            'science2_skill_name' => null,
        ]);
        $this->app->instance(InventionPlannerService::class, $planner);
    }

    protected function seedInvention(): void
    {
        $this->seedIndustryTree();
        $this->mockInventionChain();
        $this->sellOrder(20171, 100000.0);
        $this->sellOrder(20424, 50000.0);
        $this->sellOrder(34201, 1000000.0); // Accelerant Decryptor
        $this->sellOrder(11371, 30000000.0);
        $this->type(20171, 'Datacore - Mechanical Engineering', ['volume' => 0.1]);
        $this->type(34201, 'Accelerant Decryptor', ['volume' => 1]);
    }

    public static function fullRequest(): array
    {
        return [
            't2_type_id' => 11371,
            'decryptor_key' => 'accelerant',
            'encryption_skill' => 4,
            'science1_skill' => 4,
            'science2_skill' => 3,
            'invention_runs' => 3,
            'industry_skill' => 5,
            'advanced_industry_skill' => 4,
            'structure_type' => 'raitaru',
            'rig_me' => 't2',
            'rig_te' => 't1',
            'system_security' => 'nullsec',
            'reaction_structure_type' => 'athanor',
            'reaction_rig_me' => 't1',
            'reaction_system_security' => 'nullsec',
            'facility_tax' => 5,
            'system_cost_index' => 4.5,
        ];
    }
}
