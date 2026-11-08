<?php

namespace Tests\Feature;

use App\Models\BuildProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Industry slots and reprocessing moved to the React app; the old URLs and
 * route names now redirect there, and their /api aliases are gone.
 */
class IndustryPagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);
    }

    public function test_old_pages_redirect_to_the_app(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('industry.availability'))->assertRedirect('/app/industry/slots');
        $this->actingAs($user)->get(route('industry.reprocessing'))->assertRedirect('/app/industry/reprocessing');
    }

    public function test_legacy_api_aliases_are_removed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->getJson('/api/industry/availability')->assertNotFound();
        $this->actingAs($user)->getJson('/api/industry/reprocessing')->assertNotFound();
        $this->actingAs($user)->postJson('/api/industry/availability/refresh')->assertNotFound();
        $this->actingAs($user)->postJson('/api/industry/availability/clear')->assertNotFound();
    }

    public function test_dashboard_and_industry_tools_redirect_to_the_app(): void
    {
        $user = $this->user();
        $program = BuildProgram::create(['user_id' => $user->id, 'name' => 'P', 'steps' => [], 'settings' => []]);

        foreach ([
            'dashboard' => '/app/dashboard',
            'industry.jobs' => '/app/industry/jobs',
            'industry.can-produce' => '/app/industry/can-produce',
            'industry.blueprint-research' => '/app/industry/blueprint-research',
            'industry.reaction-formula-research' => '/app/industry/reaction-formula-research',
            'build-programs.index' => '/app/build-programs',
        ] as $name => $target) {
            $this->actingAs($user)->get(route($name))->assertRedirect($target);
        }

        $this->actingAs($user)->get(route('build-programs.show', $program))->assertRedirect('/app/build-programs/'.$program->id);
    }

    public function test_stock_manager_redirects_only_for_stock_managers(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('industry.stock-manager'))->assertForbidden();

        $user->forceFill(['is_stock_manager_enabled' => true])->save();
        $this->actingAs($user)->get(route('industry.stock-manager'))->assertRedirect('/app/industry/stock-manager');
    }

    public function test_csv_exports_still_download(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('industry.blueprint-research.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $this->actingAs($user)->get(route('industry.reaction-formula-research.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_more_legacy_api_aliases_are_removed(): void
    {
        $user = $this->user();
        $program = BuildProgram::create(['user_id' => $user->id, 'name' => 'P', 'steps' => [], 'settings' => []]);

        $this->actingAs($user)->postJson('/api/industry/can-produce/refresh')->assertNotFound();
        $this->actingAs($user)->getJson('/api/industry/stock-manager/search?q=ab')->assertNotFound();
        $this->actingAs($user)->postJson('/api/industry/stock-manager/items', [])->assertNotFound();
        $this->actingAs($user)->getJson("/api/build-programs/{$program->id}/progress")->assertNotFound();
        $this->actingAs($user)->getJson("/api/build-programs/{$program->id}/asset-locations")->assertNotFound();
        $this->actingAs($user)->putJson("/api/build-programs/{$program->id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/build-programs/{$program->id}")->assertNotFound();
        $this->actingAs($user)->postJson('/api/build-programs/merge', [])->assertNotFound();
    }
}
