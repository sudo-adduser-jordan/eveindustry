<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_script_included_when_configured(): void
    {
        config(['services.google_analytics.id' => 'G-TESTID123']);

        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('googletagmanager.com/gtag/js?id=G-TESTID123', false);
        $response->assertSee("gtag('config', 'G-TESTID123')", false);
    }

    public function test_analytics_script_not_included_when_not_configured(): void
    {
        config(['services.google_analytics.id' => null]);

        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertDontSee('googletagmanager.com/gtag/js', false);
    }

    public function test_cookie_consent_widget_included_when_analytics_configured(): void
    {
        config(['services.google_analytics.id' => 'G-TESTID123']);

        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('cookie-consent', false);
        $response->assertSee('Unidentified Vessel Detected', false);
        $response->assertSee('cookie_consent', false);
    }

    public function test_cookie_consent_widget_included_when_analytics_not_configured(): void
    {
        config(['services.google_analytics.id' => null]);

        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('cookie-consent', false);
        $response->assertSee('Unidentified Vessel Detected', false);
    }

    public function test_analytics_loads_only_after_consent(): void
    {
        config(['services.google_analytics.id' => 'G-TESTID123']);

        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('loadGoogleAnalytics', false);
        $response->assertSee("localStorage.getItem('cookie_consent')", false);
    }
}
