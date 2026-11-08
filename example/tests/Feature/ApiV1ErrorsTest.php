<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiV1ErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Throwaway routes in the api group to exercise error rendering.
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/_test')->group(function () {
            Route::get('/forbidden', fn () => abort(403));
            Route::get('/validate', fn (Request $request) => $request->validate(['name' => 'required']));
            Route::get('/users/{user}', fn (User $user) => $user->id);
        });
    }

    private function user(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Pilot', 'email' => 'p'.uniqid().'@test.com', 'is_active' => true], $attrs));
    }

    public function test_forbidden_is_json_with_code_even_without_accept_header(): void
    {
        $this->actingAs($this->user())
            ->get('/api/v1/_test/forbidden')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonStructure(['message', 'code']);
    }

    public function test_validation_errors_keep_laravel_shape_plus_code(): void
    {
        $this->actingAs($this->user())
            ->get('/api/v1/_test/validate')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'errors' => ['name'], 'code']);
    }

    public function test_missing_model_and_unknown_route_are_not_found(): void
    {
        $this->actingAs($this->user())
            ->get('/api/v1/_test/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        $this->get('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_web_pages_still_redirect_guests_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
