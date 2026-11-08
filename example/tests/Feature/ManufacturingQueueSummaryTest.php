<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturingQueueSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::firstOrCreate(['email' => 'queue@example.com'], ['name' => 'Test', 'is_active' => true]);
    }

    public function test_queue_summary_returns_per_item_and_total_profit_and_margin(): void
    {
        $response = $this->actingAs($this->user())->postJson('/api/v1/calculators/manufacturing/queue-summary', [
            'items' => [
                ['revenue' => 2000, 'input_cost' => 1000, 'fees' => 130],
                ['revenue' => 500, 'input_cost' => 600, 'fees' => 20],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.profit', 870)
            ->assertJsonPath('data.items.0.profit_margin', 87)
            ->assertJsonPath('data.items.1.profit', -120)
            ->assertJsonPath('data.totals.revenue', 2500)
            ->assertJsonPath('data.totals.input_cost', 1600)
            ->assertJsonPath('data.totals.fees', 150)
            ->assertJsonPath('data.totals.profit', 750)
            ->assertJsonPath('data.totals.profit_margin', 46.9);
    }

    public function test_queue_summary_validates_items(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/calculators/manufacturing/queue-summary', [
                'items' => [['revenue' => 'lots', 'input_cost' => -5]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.revenue', 'items.0.input_cost', 'items.0.fees']);

        $this->actingAs($this->user())
            ->postJson('/api/v1/calculators/manufacturing/queue-summary', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_queue_summary_requires_login(): void
    {
        $this->postJson('/api/v1/calculators/manufacturing/queue-summary', [
            'items' => [['revenue' => 1, 'input_cost' => 1, 'fees' => 0]],
        ])->assertStatus(401);
    }
}
