<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.eveonline.client_id' => 'id',
            'services.eveonline.client_secret' => 'secret',
            'install.super_admin.email' => 'root@example.test',
            'install.super_admin.password' => null,
        ]);
    }

    public function test_it_creates_the_admin_with_a_generated_password_once(): void
    {
        $this->artisan('app:install', ['--skip-data' => true, '--no-migrate' => true])
            ->expectsOutputToContain('Super admin created: root@example.test')
            ->expectsOutputToContain('Generated admin password')
            ->assertSuccessful();

        $admin = User::where('email', 'root@example.test')->firstOrFail();
        $this->assertTrue((bool) $admin->is_super_admin);

        $this->artisan('app:install', ['--skip-data' => true, '--no-migrate' => true])
            ->expectsOutputToContain('Super admin already exists')
            ->assertSuccessful();
        $this->assertSame(1, User::where('is_super_admin', true)->count());
    }

    public function test_a_weak_configured_password_is_replaced(): void
    {
        config(['install.super_admin.password' => 'password123']);

        $this->artisan('app:install', ['--skip-data' => true, '--no-migrate' => true])
            ->expectsOutputToContain('Generated admin password')
            ->assertSuccessful();

        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password123', User::where('email', 'root@example.test')->value('password')));
    }

    public function test_a_strong_configured_password_is_kept(): void
    {
        config(['install.super_admin.password' => 'correct-horse-battery-staple']);

        $this->artisan('app:install', ['--skip-data' => true, '--no-migrate' => true])->assertSuccessful();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('correct-horse-battery-staple', User::where('email', 'root@example.test')->value('password')));
    }

    public function test_it_refuses_to_run_without_sso_credentials(): void
    {
        config(['services.eveonline.client_id' => null]);

        $this->artisan('app:install', ['--skip-data' => true, '--no-migrate' => true])
            ->expectsOutputToContain('EVE_CLIENT_ID and EVE_CLIENT_SECRET are required')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_admin_reset_never_falls_back_to_a_known_password(): void
    {
        $this->artisan('admin:reset')->expectsOutputToContain('Generated admin password')->assertSuccessful();

        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password123', User::where('email', 'root@example.test')->value('password')));
    }
}
