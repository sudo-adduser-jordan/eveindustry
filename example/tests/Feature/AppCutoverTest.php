<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_opens_app_and_old_character_link_redirects(): void
    {
        $this->get('/')->assertRedirect('/app/dashboard');
        $this->get('/app/dashboard')->assertRedirect('/login');
        $user = User::create(['name' => 'Pilot', 'email' => 'pilot@test.com', 'is_active' => true]);
        $this->actingAs($user)->get('/characters')->assertRedirect('/app/characters');
    }

    public function test_public_pages_need_no_external_ui_libraries(): void
    {
        foreach (['/welcome', '/login', '/admin/login', '/auth/eve/scopes'] as $url) {
            $this->get($url)->assertOk()->assertSee('/css/auth.css', false)
                ->assertDontSee('cdn.jsdelivr.net')
                ->assertDontSee('bootstrap.bundle')->assertDontSee('bootstrap-icons');
        }
    }
}
