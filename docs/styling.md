# Styling guide

The look of Eve Industrialist is driven by CSS variables ("tokens"). You can
re-skin the whole app, and switch between looks with one setting, without
rebuilding anything. The built-in look is the default and is used whenever you
set nothing.

## Switching themes

1. A theme is a folder with a `theme.css` (and an optional `theme.json`).
2. Set `APP_THEME=<folder name>` in `.env`.
3. Restart the app to apply it.

Setting `APP_THEME=default`, leaving it empty, or naming a folder that does not
exist gives the built-in look. Names are lowercase letters, digits, `-` and `_`.

Where theme folders are looked up, in order:

| Location | Use |
|---|---|
| `public/themes/<name>/` | Themes that ship with the app (`default`, `example-light`). |
| `public/themes-custom/<name>/` | Your own themes. |

## Making a theme

```bash
mkdir -p themes/midnight
cp public/themes/example-light/theme.css themes/midnight/theme.css   # or start from default
$EDITOR themes/midnight/theme.css
echo 'APP_THEME=midnight' >> .env
```

`theme.css` loads after the app's own styles, so redefining a variable on
`:root` is enough:

```css
:root {
  --accent: #ff7a59;
  --accent-rgb: 255, 122, 89;   /* keep the -rgb twins in sync */
}
```

`theme.json` is optional:

```json
{ "name": "Midnight", "themeColor": "#0a0e1a" }
```

`themeColor` sets the browser UI colour (`<meta name="theme-color">`).

Edits to `theme.css` show up on the next page load (the URL carries the file's
modification time and nginx revalidates it).

## Token reference

Defined in `frontend/src/styles/tokens.css`. A theme may set any subset.

| Token | Default | Purpose |
|---|---|---|
| `--bg` | `#0b0f17` | Page background |
| `--panel`, `--panel2` | `#0f1626`, `#0c1322` | Cards, sidebar, inputs |
| `--text` | `#e9eefc` | Main text |
| `--muted` | `#a9b5d1` | Secondary text |
| `--line` | `rgba(255,255,255,.1)` | Borders and dividers |
| `--accent`, `--accent2` | `#7aa7ff`, `#6ee7ff` | Links, buttons, highlights |
| `--accent-rgb`, `--accent2-rgb` | `122, 167, 255`, `110, 231, 255` | The accents as `r, g, b`, used for translucent glows: `rgba(var(--accent-rgb), .2)`. **Change them whenever you change the accent.** |
| `--shadow` | `0 18px 50px rgba(0,0,0,.45)` | Panel shadow |
| `--ok`, `--warn`, `--bad` | `#7cffb2`, `#ffb347`, `#ff7a7a` | Status colours |
| `--manufacturing`, `--research`, `--reactions` | `#6fb1ff`, `#b091ff`, `#ffb86b` | Industry slot kinds |
| `--font-display`, `--font-body` | Orbitron, Rajdhani | Fonts (loaded from Google Fonts; to use others add an `@import` or `@font-face` in `theme.css`) |
| `--sidebar-width` | `260px` | Sidebar width |
| `color-scheme` | `dark` | Set to `light` for light themes so form controls match |

The login and landing pages (server-rendered) read the same variables from
`public/css/auth.css`, so a theme styles them too.

## What a theme cannot change

* The installed-app (PWA) name, icons and manifest colours are fixed at build time.
  `themeColor` still tints the browser bar.
* When the app is opened offline from the installed shell, the cached page has
  no theme link until you are back online.
* Logos and the favicon: replace `public/images/logo.svg`, `logo-icon.svg` and
  `public/favicon.svg` in your own image build, or serve them from your proxy.

## Rules for contributors

* Use tokens, not literals. New colours go in `tokens.css` first; components and
  stylesheets reference `var(--token)`. For translucent accents use
  `rgba(var(--accent-rgb), a)`.
* Keep new controls on the shared classes in `theme.css` (`.button`, `.option`,
  inputs) instead of per-page rules.
* The default look must not change unless that is the point of the change:
  compare screenshots before and after.
* Contrast: body text on `--bg` and `--panel` must reach WCAG AA (4.5:1). Check
  both the default and `example-light` themes.
* Tests: `tests/Unit/ThemeFilesTest.php` fails if a shipped theme sets a token
  that `tokens.css` does not define, or if the default copy drifts from it.
