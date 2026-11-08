<?php

namespace Tests\Feature\ApiV1\Planning;

use App\Models\StationConfig;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Planning\PlanningFixtures;
use Tests\TestCase;

/**
 * The SPA flow end to end: GET /sanctum/csrf-cookie, then the X-XSRF-TOKEN
 * header on a write, with a real session login.
 */
class CsrfFlowTest extends TestCase
{
    use PlanningFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel skips CSRF while running unit tests; turn the check back on.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function body(): array
    {
        return ['name' => 'Home', 'structure_type' => 'raitaru', 'rig_me' => 't1', 'rig_te' => 'none', 'system_security' => 'highsec'];
    }

    public function test_write_succeeds_with_csrf_cookie_and_header(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $cookie = $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost'])
            ->get('/sanctum/csrf-cookie');
        $cookie->assertNoContent();
        $token = $cookie->getCookie('XSRF-TOKEN', false)->getValue();

        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost', 'X-XSRF-TOKEN' => $token])
            ->postJson('/api/v1/station-configs', $this->body())
            ->assertStatus(201)->assertJsonPath('data.name', 'Home');
        $this->assertSame(1, StationConfig::count());
    }

    public function test_write_without_header_is_419_csrf_token_mismatch(): void
    {
        $this->actingAs($this->makeUser(), 'web');

        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost'])
            ->postJson('/api/v1/station-configs', $this->body())
            ->assertStatus(419)->assertJsonPath('code', 'csrf_token_mismatch');
        $this->assertSame(0, StationConfig::count());
    }
}
