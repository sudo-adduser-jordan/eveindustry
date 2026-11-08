<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDocsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Pilot', 'email' => 'p'.uniqid().'@test.com', 'is_active' => true], $attrs));
    }

    public function test_api_docs_ui_is_admin_only_outside_local(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->actingAs($this->user())->get('/docs/api')->assertForbidden();

        $this->actingAs($this->user(['is_admin' => true]))->get('/docs/api')->assertOk();
    }

    public function test_spec_documents_only_v1_routes(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true);

        $this->assertSame('/api/v1', $spec['servers'][0]['url']);

        // Exactly the registered api/v1 routes: nothing from the older /api/... web routes
        $v1Paths = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/'))
            ->map(fn ($uri) => substr($uri, strlen('api/v1')))
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($v1Paths, array_keys($spec['paths']));
        $this->assertContains('/me', array_keys($spec['paths']));
    }
}
