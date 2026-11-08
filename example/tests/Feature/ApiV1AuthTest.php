<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Makes Sanctum treat the request as a same-origin SPA call (session started). */
    private const SPA_HEADERS = ['Referer' => 'http://localhost/', 'Accept' => 'application/json'];

    private function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Pilot',
            'email' => 'u'.uniqid().'@test.com',
            'is_active' => true,
        ], $attrs));
    }

    private function makeCharacter(User $user, int $id, array $attrs = []): Character
    {
        return Character::create(array_merge([
            'character_id' => $id,
            'user_id' => $user->id,
            'name' => 'Char '.$id,
            'corporation_id' => 98000001,
            'corporation_name' => 'Corp',
            'is_main' => false,
            'access_token' => 'secret-access-'.$id,
            'refresh_token' => 'secret-refresh-'.$id,
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-assets.read_assets.v1'],
        ], $attrs));
    }

    public function test_me_requires_auth_and_returns_json_401_with_code(): void
    {
        // No Accept header: /api/v1 must still answer with JSON, not a login redirect.
        $this->get('/api/v1/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);

        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_me_returns_user_characters_scopes_and_flags(): void
    {
        $user = $this->makeUser(['is_admin' => true, 'is_stock_manager_enabled' => true]);
        $this->makeCharacter($user, 2001, ['name' => 'Zed Alt']);
        $this->makeCharacter($user, 2002, [
            'name' => 'Main Pilot',
            'is_main' => true,
            'alliance_id' => 99000001,
            'alliance_name' => 'Alliance',
            'scopes' => ['esi-skills.read_skills.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => 'Pilot',
                    'display_name' => 'Main Pilot',
                    'avatar_url' => 'https://images.evetech.net/characters/2002/portrait?size=128',
                    'admin' => true,
                    'stock_manager' => true,
                    'demo' => false,
                    'source_url' => config('app.source_url'),
                    'discord_url' => null,
                    'features' => ['discord_bot' => false],
                    'characters' => [
                        [
                            'character_id' => 2002,
                            'name' => 'Main Pilot',
                            'corporation_id' => 98000001,
                            'corporation_name' => 'Corp',
                            'alliance_id' => 99000001,
                            'alliance_name' => 'Alliance',
                            'is_main' => true,
                            'is_esi_active' => true,
                            'cached_location' => null,
                            'cached_training' => null,
                            'portrait_url' => 'https://images.evetech.net/characters/2002/portrait?size=128',
                            'scopes' => ['esi-skills.read_skills.v1', 'esi-industry.read_character_jobs.v1'],
                        ],
                        [
                            'character_id' => 2001,
                            'name' => 'Zed Alt',
                            'corporation_id' => 98000001,
                            'corporation_name' => 'Corp',
                            'alliance_id' => null,
                            'alliance_name' => null,
                            'is_main' => false,
                            'is_esi_active' => true,
                            'cached_location' => null,
                            'cached_training' => null,
                            'portrait_url' => 'https://images.evetech.net/characters/2001/portrait?size=128',
                            'scopes' => ['esi-assets.read_assets.v1'],
                        ],
                    ],
                ],
                'meta' => [],
            ]);

        $body = $response->getContent();
        $this->assertStringContainsString('"meta":{}', $body);
        $this->assertStringNotContainsString('secret-', $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('refresh_token', $body);
        $this->assertStringNotContainsString('password', $body);
    }

    public function test_me_flags_default_false_and_super_admin_counts_as_admin(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.admin', false)
            ->assertJsonPath('data.stock_manager', false)
            ->assertJsonPath('data.characters', []);

        $super = $this->makeUser(['is_super_admin' => true]);
        $this->actingAs($super)->getJson('/api/v1/me')->assertJsonPath('data.admin', true);
    }

    public function test_me_works_for_a_same_origin_spa_request(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->withHeaders(self::SPA_HEADERS)
            ->get('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_ends_the_web_session_and_returns_204(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->withHeaders(self::SPA_HEADERS)
            ->withSession(['marker' => 'kept-before-logout'])
            ->post('/api/v1/logout')
            ->assertNoContent()
            ->assertSessionMissing('marker');

        $this->assertGuest('web');

        // Guards cache the user per app instance; drop them as a new request would.
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA_HEADERS)->get('/api/v1/me')->assertStatus(401);
    }

    public function test_logout_requires_auth(): void
    {
        $this->postJson('/api/v1/logout')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }
}
