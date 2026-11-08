<?php

namespace Tests\Feature\ApiV1\Programs;

use App\Models\StationConfig;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

/**
 * The SPA calls write endpoints with the Sanctum stateful session cookie:
 * GET /sanctum/csrf-cookie first, then the X-XSRF-TOKEN header.
 */
class CsrfFlowTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel skips CSRF checks while unit tests run; switch them back on.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'activity_type' => 'manufacturing',
            'name' => 'Home Raitaru',
            'structure_type' => 'raitaru',
            'rig_me' => 't1',
            'rig_te' => 'none',
            'system_security' => 'lowsec',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statefulHeaders(): array
    {
        return ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/structures'];
    }

    public function test_post_succeeds_with_the_csrf_cookie_and_token_header(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $cookie = $this->withHeaders($this->statefulHeaders())->get('/sanctum/csrf-cookie');
        $cookie->assertNoContent();
        $token = $cookie->getCookie('XSRF-TOKEN', false);
        $session = $cookie->getCookie(config('session.cookie'), false);
        $this->assertNotNull($token, 'the csrf-cookie endpoint sets XSRF-TOKEN');
        $this->assertNotNull($session, 'the csrf-cookie endpoint starts the session');

        // A browser sends both cookies back as received (still encrypted).
        $this->withUnencryptedCookies([
            config('session.cookie') => $session->getValue(),
            'XSRF-TOKEN' => $token->getValue(),
        ])->withHeaders($this->statefulHeaders() + ['X-XSRF-TOKEN' => $token->getValue()])
            ->postJson('/api/v1/structures', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Home Raitaru');

        $this->assertSame(1, StationConfig::where('user_id', $user->id)->count());
    }

    public function test_post_without_the_token_header_is_419_csrf_token_mismatch(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $cookie = $this->withHeaders($this->statefulHeaders())->get('/sanctum/csrf-cookie');
        $cookie->assertNoContent();

        // Same session and cookies, but the X-XSRF-TOKEN header is missing.
        $this->withUnencryptedCookies([
            config('session.cookie') => $cookie->getCookie(config('session.cookie'), false)->getValue(),
            'XSRF-TOKEN' => $cookie->getCookie('XSRF-TOKEN', false)->getValue(),
        ])->withHeaders($this->statefulHeaders())
            ->postJson('/api/v1/structures', $this->payload())
            ->assertStatus(419)
            ->assertJsonPath('code', 'csrf_token_mismatch');

        $this->assertSame(0, StationConfig::count());
    }
}
