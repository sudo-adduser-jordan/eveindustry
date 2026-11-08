<?php

namespace Tests\Feature;

use App\Services\HealthService;
use Tests\TestCase;

class ApiV1HealthTest extends TestCase
{
    public function test_health_is_public_and_reports_each_check(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                    'checks' => ['database' => 'ok', 'cache' => 'ok'],
                    'setup' => ['ready' => false, 'steps' => ['sde' => false, 'prices' => false, 'orders' => false]],
                ],
                'meta' => [],
            ]);
    }

    public function test_health_is_503_when_a_check_fails(): void
    {
        $this->mock(HealthService::class)
            ->shouldReceive('check')
            ->andReturn(['status' => 'failed', 'checks' => ['database' => 'failed', 'cache' => 'ok']]);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.checks.database', 'failed');
    }

    public function test_framework_health_route_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
