<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Blade calculator pages that moved to the React app keep their URLs
 * and route names and redirect to the app.
 */
class CalculatorRedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ported_calculator_urls_redirect_to_the_app(): void
    {
        $user = User::create(['name' => 'Calc', 'email' => 'redirect@example.com', 'is_active' => true]);

        foreach ([
            'calculators.manufacturing' => '/app/calculators/manufacturing',
            'calculators.manufacturing-browser' => '/app/calculators/manufacturing-browser',
            'calculators.reaction-browser' => '/app/calculators/reaction-browser',
        ] as $name => $target) {
            $this->actingAs($user)->get(route($name))->assertRedirect($target);
        }
    }

    public function test_guests_are_sent_to_login_first(): void
    {
        $this->get(route('calculators.manufacturing'))->assertRedirect(route('login'));
    }
}
