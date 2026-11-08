<?php

namespace Tests\Feature;

use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SponsorTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'User',
            'email' => 'user@test.com',
            'is_active' => true,
        ]);
    }

    public function test_welcome_page_shows_features(): void
    {
        $response = $this->get(route('welcome'));

        $response->assertStatus(200);
        $response->assertSee('Industry Job Tracking');
        $response->assertSee('Multi-Character Support');
        $response->assertSee('Profit Calculators');
        $response->assertSee('Slot Availability');
        $response->assertSee('Reprocessing Analysis');
        $response->assertSee('Planetary Interaction');
        $response->assertSee('Advanced Reaction Calculator');
        $response->assertSee('Asset Management');
        $response->assertSee('Blueprint Research');
        $response->assertSee('Build Programs');
        $response->assertSee('Invention Planner');
        $response->assertSee('Contract Tools');
        $response->assertSee('Moon Scan Analyser');
        // Removed from the welcome page in adab2b1
        $response->assertDontSee('Wormhole Explorer');
        $response->assertDontSee('Corporate Management');
    }

    public function test_welcome_page_shows_eve_sso_cta(): void
    {
        $response = $this->get(route('welcome'));

        $response->assertStatus(200);
        $response->assertSee('Login with EVE Online');
        $response->assertSee('Get Started with EVE SSO');
    }

    public function test_welcome_page_shows_active_sponsors(): void
    {
        $active = Sponsor::create([
            'name' => 'Active Sponsor',
            'url' => 'https://example.com',
            'image_path' => 'sponsors/test.png',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $inactive = Sponsor::create([
            'name' => 'Inactive Sponsor',
            'url' => 'https://inactive.com',
            'image_path' => 'sponsors/inactive.png',
            'is_active' => false,
            'sort_order' => 1,
        ]);

        $response = $this->get(route('welcome'));

        $response->assertStatus(200);
        $response->assertSee('Active Sponsor');
        $response->assertDontSee('Inactive Sponsor');
    }

    public function test_old_admin_urls_redirect_to_the_app(): void
    {
        $admin = $this->createAdmin();
        $sponsor = Sponsor::create(['name' => 'S', 'url' => 'https://s.test', 'image_path' => 'sponsors/s.png']);

        $this->actingAs($admin)->get(route('admin.sponsors.index'))->assertRedirect('/app/admin/sponsors');
        $this->actingAs($admin)->get(route('admin.sponsors.create'))->assertRedirect('/app/admin/sponsors/new');
        $this->actingAs($admin)->get(route('admin.sponsors.edit', $sponsor))->assertRedirect("/app/admin/sponsors/{$sponsor->id}");
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect('/app/admin/dashboard');
        $this->actingAs($admin)->get(route('admin.users'))->assertRedirect('/app/admin/users');
        $this->actingAs($admin)->get(route('admin.users.industry-jobs', $admin))->assertRedirect("/app/admin/users/{$admin->id}/industry-jobs");
        $this->actingAs($admin)->get(route('admin.settings'))->assertRedirect('/app/admin/settings');
    }

    public function test_old_admin_urls_still_turn_non_admins_away(): void
    {
        $this->actingAs($this->createUser())->get(route('admin.sponsors.index'))->assertRedirect(route('dashboard'));
    }
}
