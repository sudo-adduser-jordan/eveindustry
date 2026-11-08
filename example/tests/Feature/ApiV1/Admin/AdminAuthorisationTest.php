<?php

namespace Tests\Feature\ApiV1\Admin;

use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every /api/v1/admin endpoint: guest 401, non-admin 403, admin allowed.
 */
class AdminAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'dashboard' => ['GET', '/api/v1/admin/dashboard'],
            'users' => ['GET', '/api/v1/admin/users'],
            'toggle status' => ['POST', '/api/v1/admin/users/{user}/toggle-status'],
            'toggle admin' => ['POST', '/api/v1/admin/users/{user}/toggle-admin'],
            'toggle stock manager' => ['POST', '/api/v1/admin/users/{user}/toggle-stock-manager'],
            'delete user' => ['DELETE', '/api/v1/admin/users/{user}'],
            'user industry jobs' => ['GET', '/api/v1/admin/users/{user}/industry-jobs'],
            'sponsors' => ['GET', '/api/v1/admin/sponsors'],
            'create sponsor' => ['POST', '/api/v1/admin/sponsors'],
            'show sponsor' => ['GET', '/api/v1/admin/sponsors/{sponsor}'],
            'update sponsor' => ['POST', '/api/v1/admin/sponsors/{sponsor}'],
            'delete sponsor' => ['DELETE', '/api/v1/admin/sponsors/{sponsor}'],
            'account' => ['GET', '/api/v1/admin/account'],
            'update account' => ['PUT', '/api/v1/admin/account'],
            'esi diagnostics summary' => ['GET', '/api/v1/admin/esi-diagnostics/summary'],
            'esi diagnostics trends' => ['GET', '/api/v1/admin/esi-diagnostics/trends'],
            'esi diagnostics endpoints' => ['GET', '/api/v1/admin/esi-diagnostics/endpoints'],
            'esi diagnostics events' => ['GET', '/api/v1/admin/esi-diagnostics/events'],
            'esi diagnostics batches' => ['GET', '/api/v1/admin/esi-diagnostics/batches'],
            'esi diagnostics health' => ['GET', '/api/v1/admin/esi-diagnostics/health'],
            'discord bot' => ['GET', '/api/v1/admin/discord-bot'],
            'update discord bot' => ['PUT', '/api/v1/admin/discord-bot'],
        ];
    }

    private function url(string $path, User $target, Sponsor $sponsor): string
    {
        return str_replace(['{user}', '{sponsor}'], [$target->id, $sponsor->id], $path);
    }

    private function fixtures(): array
    {
        $target = User::create(['name' => 'Target', 'email' => 'target@test.com', 'is_active' => true]);
        $sponsor = Sponsor::create(['name' => 'S', 'url' => 'https://s.test', 'image_path' => 'sponsors/s.png']);

        return [$target, $sponsor];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_guest_gets_401(string $method, string $path): void
    {
        [$target, $sponsor] = $this->fixtures();

        $this->json($method, $this->url($path, $target, $sponsor))
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    /**
     * @dataProvider endpoints
     */
    public function test_non_admin_gets_403_and_changes_nothing(string $method, string $path): void
    {
        [$target, $sponsor] = $this->fixtures();
        $user = User::create(['name' => 'Plain', 'email' => 'plain@test.com', 'is_active' => true]);

        $this->actingAs($user)
            ->json($method, $this->url($path, $target, $sponsor))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true, 'is_admin' => false]);
        $this->assertDatabaseHas('sponsors', ['id' => $sponsor->id]);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_admin_is_let_through(string $method, string $path): void
    {
        [$target, $sponsor] = $this->fixtures();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'is_admin' => true, 'is_active' => true]);

        $status = $this->actingAs($admin)
            ->json($method, $this->url($path, $target, $sponsor))
            ->status();

        // Writes with no body fail validation (422); the point is the guard let them in.
        $this->assertNotContains($status, [401, 403]);
        $this->assertLessThan(500, $status, "$method $path");
    }

    public function test_me_reports_the_admin_flag(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'is_admin' => true, 'is_active' => true]);
        $user = User::create(['name' => 'Plain', 'email' => 'plain@test.com', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.admin', true);
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.admin', false);
    }
}
