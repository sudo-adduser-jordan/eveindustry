<?php

namespace App\Services;

/**
 * Resolves the active visual theme. A theme is a folder with a theme.css (CSS
 * variable overrides and optional rules, loaded after the app's own styles)
 * and an optional theme.json. Shipped themes live in public/themes/<name>/;
 * operators add their own in public/themes-custom/<name>/ (the self-hosting
 * compose file mounts ./themes there). The folder named by APP_THEME is used;
 * anything missing or malformed falls back to the built-in look.
 * See docs/styling.md.
 */
class ThemeService
{
    public const DEFAULT = 'default';

    /** URL/directory roots searched in order; the first holding the theme wins. */
    private const ROOTS = ['themes', 'themes-custom'];

    /**
     * @return array{name: string, css_url: string, theme_color: string|null}|null
     *                                                                              Null when the built-in look applies (no override file needed).
     */
    public function active(): ?array
    {
        $name = (string) config('app.theme', self::DEFAULT);

        if ($name === '' || $name === self::DEFAULT || ! $this->isValidName($name)) {
            return null;
        }

        $root = $this->rootOf($name);
        if ($root === null) {
            return null;
        }

        return [
            'name' => $name,
            'css_url' => "/{$root}/{$name}/theme.css?v=".filemtime(public_path("{$root}/{$name}/theme.css")),
            'theme_color' => $this->meta($root, $name)['theme_color'] ?? null,
        ];
    }

    /** @return list<string> Theme folder names that can be selected. */
    public function available(): array
    {
        $names = [self::DEFAULT];
        foreach (self::ROOTS as $root) {
            foreach (glob(public_path("{$root}/*/theme.css")) ?: [] as $file) {
                $name = basename(dirname($file));
                if ($name !== self::DEFAULT && $this->isValidName($name) && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    public function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name);
    }

    /**
     * Rewrites a built index.html for the active theme: adds the stylesheet
     * after the app's own, marks <html data-theme>, and applies theme_color.
     */
    public function apply(string $html): string
    {
        $theme = $this->active();
        if ($theme === null) {
            return $html;
        }

        $link = '<link rel="stylesheet" id="theme-css" href="'.e($theme['css_url']).'" />';
        $html = preg_replace('#</head>#i', $link."\n  </head>", $html, 1) ?? $html;
        $html = preg_replace('#<html\b#i', '<html data-theme="'.e($theme['name']).'"', $html, 1) ?? $html;

        if ($theme['theme_color'] !== null) {
            $html = preg_replace(
                '#(<meta\s+name="theme-color"\s+content=")[^"]*(")#i',
                '${1}'.e($theme['theme_color']).'${2}',
                $html,
                1,
            ) ?? $html;
        }

        return $html;
    }

    private function rootOf(string $name): ?string
    {
        foreach (self::ROOTS as $root) {
            if (is_file(public_path("{$root}/{$name}/theme.css"))) {
                return $root;
            }
        }

        return null;
    }

    /** @return array{theme_color?: string} */
    private function meta(string $root, string $name): array
    {
        $file = public_path("{$root}/{$name}/theme.json");
        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $color = is_array($data) ? ($data['themeColor'] ?? null) : null;

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? ['theme_color' => $color] : [];
    }
}
