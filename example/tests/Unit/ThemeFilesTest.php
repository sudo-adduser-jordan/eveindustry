<?php

namespace Tests\Unit;

use Tests\TestCase;

/** Shipped themes may only set variables that the app defines. */
class ThemeFilesTest extends TestCase
{
    public function test_shipped_themes_only_use_known_tokens(): void
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', (string) file_get_contents(base_path('frontend/src/styles/tokens.css')), $known);
        $this->assertNotEmpty($known[1]);

        $themes = glob(base_path('public/themes/*/theme.css'));
        $this->assertNotEmpty($themes);

        foreach ($themes as $file) {
            preg_match_all('/(--[a-z0-9-]+)\s*:/i', (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file)), $used);
            $this->assertSame([], array_values(array_diff($used[1], $known[1])), basename(dirname($file)).' sets unknown tokens');
            $this->assertIsArray(json_decode((string) file_get_contents(dirname($file).'/theme.json'), true), basename(dirname($file)).' needs a valid theme.json');
        }
    }

    public function test_the_default_theme_copy_matches_the_built_in_tokens(): void
    {
        $tokens = (string) file_get_contents(base_path('frontend/src/styles/tokens.css'));
        $copy = (string) file_get_contents(base_path('public/themes/default/theme.css'));

        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $copy, $pairs, PREG_SET_ORDER);
        $this->assertNotEmpty($pairs);
        foreach ($pairs as [, $name, $value]) {
            $this->assertStringContainsString("{$name}: ".trim($value).';', $tokens, "{$name} drifted from tokens.css");
        }
    }
}
