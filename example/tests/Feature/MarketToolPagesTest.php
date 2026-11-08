<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketToolPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_tool_urls_redirect_to_the_app(): void
    {
        $user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => bcrypt('x'), 'is_active' => true]);

        $this->actingAs($user)->get(route('structures.index'))->assertRedirect('/app/structures');
        $this->actingAs($user)->get(route('tools.contract-appraisal'))->assertRedirect('/app/tools/contract-appraisal');
        $this->actingAs($user)->get(route('tools.contract-browser'))->assertRedirect('/app/tools/contract-browser');
        $this->actingAs($user)->get(route('tools.contract-detail', 123))->assertRedirect('/app/tools/contract-browser/123');
    }

    public function test_legacy_aliases_are_removed(): void
    {
        $user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => bcrypt('x'), 'is_active' => true]);

        $this->actingAs($user)->getJson('/api/structures')->assertNotFound();
        $this->actingAs($user)->postJson('/api/tools/contract-appraisal', ['items' => 'Tritanium'])->assertNotFound();
    }
}
