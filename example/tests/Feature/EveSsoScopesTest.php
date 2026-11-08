<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class EveSsoScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_page_is_accessible_as_guest(): void
    {
        $response = $this->get(route('auth.eve.scopes'));

        $response->assertOk();
        $response->assertViewIs('auth.sso-scopes');
    }

    public function test_scopes_page_is_accessible_when_authenticated(): void
    {
        $user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('auth.eve.scopes'));

        $response->assertOk();
        $response->assertViewIs('auth.sso-scopes');
    }

    public function test_scopes_page_passes_required_and_optional_scopes_to_view(): void
    {
        $response = $this->get(route('auth.eve.scopes'));

        $response->assertViewHas('requiredScopes');
        $response->assertViewHas('optionalScopes');

        $required = $response->viewData('requiredScopes');
        $optional = $response->viewData('optionalScopes');

        $this->assertNotEmpty($required);
        $this->assertNotEmpty($optional);

        // Each entry should have scope, label, description keys
        foreach ($required as $scope) {
            $this->assertArrayHasKey('scope', $scope);
            $this->assertArrayHasKey('label', $scope);
            $this->assertArrayHasKey('description', $scope);
        }

        foreach ($optional as $scope) {
            $this->assertArrayHasKey('scope', $scope);
            $this->assertArrayHasKey('label', $scope);
            $this->assertArrayHasKey('description', $scope);
        }
    }

    public function test_scopes_page_contains_form_pointing_to_auth_eve(): void
    {
        $response = $this->get(route('auth.eve.scopes'));

        $response->assertSee(route('auth.eve'), false);
        $response->assertSee('scopes_configured', false);
        $response->assertSee('optional_scopes[]', false);
    }

    public function test_redirect_without_scopes_configured_uses_all_scopes(): void
    {
        Socialite::shouldReceive('driver->scopes->redirect')
            ->once()
            ->andReturn(redirect()->away('https://login.eveonline.com/'));

        $response = $this->get(route('auth.eve'));

        $response->assertRedirect();
    }

    public function test_optional_scopes_include_corporation_projects_scope(): void
    {
        $response = $this->get(route('auth.eve.scopes'));

        $optional = $response->viewData('optionalScopes');
        $scopeValues = array_column($optional, 'scope');

        $this->assertContains('esi-corporations.read_projects.v1', $scopeValues);
    }

    public function test_redirect_with_scopes_configured_only_includes_allowed_optional_scopes(): void
    {
        Socialite::shouldReceive('driver->scopes->redirect')
            ->once()
            ->andReturn(redirect()->away('https://login.eveonline.com/'));

        // Passes a valid optional scope plus an unknown scope; should still redirect
        $response = $this->get(route('auth.eve', [
            'scopes_configured' => '1',
            'optional_scopes' => ['esi-planets.manage_planets.v1', 'esi-evil.do_bad_things.v1'],
        ]));

        $response->assertRedirect();
    }

    public function test_redirect_with_scopes_configured_and_no_optional_scopes_still_redirects(): void
    {
        Socialite::shouldReceive('driver->scopes->redirect')
            ->once()
            ->andReturn(redirect()->away('https://login.eveonline.com/'));

        $response = $this->get(route('auth.eve', [
            'scopes_configured' => '1',
        ]));

        $response->assertRedirect();
    }
}
