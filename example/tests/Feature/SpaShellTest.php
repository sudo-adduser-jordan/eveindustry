<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    private string $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = public_path('app/index.html');
    }

    protected function tearDown(): void
    {
        // Only remove what this test wrote, never a real build.
        if (File::exists($this->index) && str_contains(File::get($this->index), 'spa-shell-test')) {
            File::delete($this->index);
        }
        // Leave no empty public/app behind: nginx answers 403 for a directory without an index.
        if (File::isDirectory(dirname($this->index)) && File::isEmptyDirectory(dirname($this->index))) {
            File::deleteDirectory(dirname($this->index));
        }
        parent::tearDown();
    }

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->get('/app/characters')->assertRedirect('/login');
    }

    public function test_signed_in_users_get_the_built_shell_on_any_client_route(): void
    {
        if (File::exists($this->index)) {
            $this->markTestSkipped('A real build is present.');
        }
        File::ensureDirectoryExists(dirname($this->index));
        File::put($this->index, '<!doctype html><div id="root"></div><!-- spa-shell-test -->');

        $user = $this->user();

        foreach (['/app', '/app/', '/app/characters', '/app/market-status/deep/link'] as $path) {
            $response = $this->actingAs($user)->get($path);
            $response
                ->assertOk()
                ->assertSee('spa-shell-test', false)
                ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
            $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        }
    }

    public function test_an_unbuilt_app_says_so_instead_of_erroring(): void
    {
        if (File::exists($this->index)) {
            $this->markTestSkipped('A real build is present.');
        }

        $this->actingAs($this->user())->get('/app')->assertStatus(503);
    }
}
