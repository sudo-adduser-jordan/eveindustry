<?php

namespace Tests\Feature\ApiV1\Admin;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'is_admin' => true, 'is_active' => true]);
    }

    private function user(string $name = 'U', array $extra = []): User
    {
        return User::create(array_merge(['name' => $name, 'email' => strtolower($name).uniqid().'@test.com', 'is_active' => true], $extra));
    }

    private function superAdmin(): User
    {
        return $this->user('Super', ['is_super_admin' => true]);
    }

    public function test_dashboard_counts_and_series(): void
    {
        $admin = $this->admin();
        $this->user('Recent', ['last_login_at' => now()->subHours(6)]);
        $this->user('Old', ['last_login_at' => now()->subDays(5)])->forceFill(['created_at' => now()->subMonths(2)])->save();

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.users', 3)
            ->assertJsonPath('data.stats.new_users_week', 2)
            ->assertJsonPath('data.stats.new_users_month', 2)
            ->assertJsonPath('data.stats.logged_in_24h', 1)
            ->assertJsonPath('data.stats.recent_logins', 2)
            ->assertJsonPath('data.registrations.0.date', now()->toDateString())
            ->assertJsonPath('data.registrations.0.count', 2)
            ->assertJsonCount(3, 'data.recent_users');
    }

    public function test_users_list_sorts_searches_and_paginates(): void
    {
        $admin = $this->admin();
        $this->user('Zebra');
        $alpha = $this->user('Alpha');
        Character::create(['character_id' => 1, 'user_id' => $alpha->id, 'name' => 'Hidden Pilot', 'corporation_id' => 1, 'is_main' => true]);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Admin')
            ->assertJsonPath('data.1.display_name', 'Hidden Pilot')
            ->assertJsonPath('data.2.display_name', 'Zebra')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.per_page', 20);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=hidden')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.character_count', 1)
            ->assertJsonPath('data.0.role', 'user');

        $this->actingAs($admin)->getJson('/api/v1/admin/users?sort=password')->assertStatus(422);

        for ($i = 0; $i < 22; $i++) {
            $this->user("Bulk{$i}");
        }
        $this->actingAs($admin)->getJson('/api/v1/admin/users?page=2')
            ->assertJsonPath('meta.pagination.page', 2)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonCount(5, 'data');
    }

    public function test_toggle_status_admin_and_stock_manager(): void
    {
        $admin = $this->admin();
        $target = $this->user();

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/toggle-status")
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/toggle-admin")
            ->assertOk()->assertJsonPath('data.role', 'admin');
        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/toggle-admin")
            ->assertOk()->assertJsonPath('data.role', 'user');

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/toggle-stock-manager")
            ->assertOk()->assertJsonPath('data.is_stock_manager_enabled', true);
        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/toggle-stock-manager")
            ->assertOk()->assertJsonPath('data.is_stock_manager_enabled', false);
    }

    public function test_super_admin_cannot_be_modified_or_deleted(): void
    {
        $admin = $this->admin();
        $super = $this->superAdmin();

        foreach (['toggle-status', 'toggle-admin'] as $action) {
            $this->actingAs($admin)->postJson("/api/v1/admin/users/{$super->id}/{$action}")
                ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$super->id}")
            ->assertStatus(403)->assertJsonPath('message', 'Cannot delete super admin.');

        $fresh = $super->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertFalse((bool) $fresh->is_admin);
    }

    public function test_delete_removes_user_and_characters(): void
    {
        $admin = $this->admin();
        $target = $this->user();
        Character::create(['character_id' => 12345, 'user_id' => $target->id, 'name' => 'Pilot', 'corporation_id' => 1, 'is_main' => true]);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$target->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('characters', ['character_id' => 12345]);
    }

    public function test_impersonation_endpoints_do_not_exist(): void
    {
        $admin = $this->admin();
        $target = $this->user();

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/impersonate")->assertNotFound();
        $this->actingAs($admin)->postJson('/api/v1/impersonation/stop')->assertNotFound();
    }

    public function test_industry_jobs_for_a_user_with_no_characters(): void
    {
        $admin = $this->admin();
        $target = $this->user('Target');

        $this->actingAs($admin)->getJson("/api/v1/admin/users/{$target->id}/industry-jobs?region_id=1")
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.region_id', 10000002)
            ->assertJsonPath('data.groups', [])
            ->assertJsonPath('data.summary', [])
            ->assertJsonPath('data.grand_total', null)
            ->assertJsonPath('data.regions.0', ['id' => 10000002, 'name' => 'The Forge (Jita)']);
    }
}
