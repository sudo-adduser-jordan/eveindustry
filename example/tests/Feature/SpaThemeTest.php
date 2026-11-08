<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_carries_the_active_theme(): void
    {
        $public = sys_get_temp_dir().'/spa-'.uniqid();
        File::ensureDirectoryExists($public.'/app');
        File::ensureDirectoryExists($public.'/themes/ocean');
        file_put_contents($public.'/app/index.html', '<html><head></head><body><div id="root"></div></body></html>');
        file_put_contents($public.'/themes/ocean/theme.css', ':root{--accent:#0af}');
        $this->app->usePublicPath($public);
        config(['app.theme' => 'ocean']);

        try {
            $this->actingAs(User::create(['name' => 'Pilot', 'email' => 'p@example.test', 'password' => bcrypt('x'), 'is_active' => true]))->get('/app/dashboard')
                ->assertOk()
                ->assertSee('data-theme="ocean"', false)
                ->assertSee('/themes/ocean/theme.css', false);
        } finally {
            File::deleteDirectory($public);
        }
    }
}
