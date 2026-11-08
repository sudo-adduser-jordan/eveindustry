<?php

namespace Tests\Unit;

use App\Services\ThemeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ThemeServiceTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = sys_get_temp_dir().'/themes-'.uniqid();
        File::ensureDirectoryExists($this->public.'/themes/ocean');
        file_put_contents($this->public.'/themes/ocean/theme.css', ':root{--accent:#0af}');
        file_put_contents($this->public.'/themes/ocean/theme.json', '{"themeColor":"#001122"}');
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->public);
        parent::tearDown();
    }

    private function html(): string
    {
        return '<!doctype html><html lang="en"><head><meta name="theme-color" content="#0d1520" /></head><body></body></html>';
    }

    public function test_the_default_theme_changes_nothing(): void
    {
        config(['app.theme' => 'default']);

        $this->assertNull(app(ThemeService::class)->active());
        $this->assertSame($this->html(), app(ThemeService::class)->apply($this->html()));
    }

    public function test_a_theme_is_injected_into_the_shell(): void
    {
        config(['app.theme' => 'ocean']);

        $out = app(ThemeService::class)->apply($this->html());

        $this->assertStringContainsString('<html data-theme="ocean" lang="en">', $out);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" id="theme-css" href="/themes/ocean/theme.css\?v=\d+" />\s*</head>#', $out);
        $this->assertStringContainsString('<meta name="theme-color" content="#001122" />', $out);
    }

    public function test_unknown_or_unsafe_names_fall_back_to_the_default(): void
    {
        foreach (['missing', '../etc', 'Ocean', 'a b', ''] as $name) {
            config(['app.theme' => $name]);
            $this->assertNull(app(ThemeService::class)->active(), "name: {$name}");
        }
    }

    public function test_operator_themes_are_found_in_themes_custom(): void
    {
        File::ensureDirectoryExists($this->public."/themes-custom/mine");
        file_put_contents($this->public."/themes-custom/mine/theme.css", ":root{--bg:#000}");
        config(["app.theme" => "mine"]);

        $this->assertStringStartsWith("/themes-custom/mine/theme.css?v=", app(ThemeService::class)->active()["css_url"]);
        $this->assertContains("mine", app(ThemeService::class)->available());
    }

    public function test_available_lists_installed_themes(): void
    {
        $this->assertSame(['default', 'ocean'], app(ThemeService::class)->available());
    }
}
