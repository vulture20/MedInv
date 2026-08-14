# Bundled UI templates

Mirrors [`languagepacks/`](../languagepacks/) at the project root, but for
UI color themes instead of translations (briefing 10./11.4, GitHub issue
#11). Any `*.json` file placed here is picked up by
`App\Domain\Templates\BundledTemplateRegistry` and pre-installed
automatically on the next boot (`DatabaseSeeder`, safe to run repeatedly —
it never overwrites a template an admin has since edited), and is always
available for an admin to (re)install on demand from the "Templates" admin
page.

Currently empty: the two ship-with templates (`light`/`dark`) are compiled
directly into `frontend/src/index.css`'s static `:root` /
`:root[data-template='dark']` rules, not files here — those two codes are
in fact reserved and rejected if you try to add a `light.json` or
`dark.json` here (`TemplateController::store()`). This directory is for
*additional* templates beyond the two bundled ones, the same way
`languagepacks/` never contains `de.json`/`en.json`.

## Format

```json
{
  "code": "high-contrast",
  "name": "High Contrast",
  "colors": {
    "color-bg": "#ffffff",
    "color-surface": "#ffffff",
    "color-text": "#000000",
    "color-text-muted": "#000000",
    "color-border": "#000000",
    "color-accent": "#0000ee",
    "color-danger": "#b00020",
    "color-danger-bg": "#ffffff",
    "color-scheme": "light"
  }
}
```

`colors` must contain every key above — unlike a language pack's
`translations` (allowed to be partial; a missing string just falls back to
English), a template missing a color leaves that one UI element unstyled
rather than degrading gracefully, so both `TemplateController` and
`BundledTemplateRegistry` reject an incomplete one outright (see
`App\Models\Template::REQUIRED_COLOR_KEYS`, the single source of truth for
this list). Each key is the literal CSS custom-property name (without the
leading `--`) it becomes — `frontend/src/theme/ThemeContext.tsx` applies a
non-built-in template via
`document.documentElement.style.setProperty('--' + key, value)` for each
one, plus `color-scheme` (a real CSS property, not a custom one, but set
the same way) for native browser UI (scrollbars, form controls) to pick up
a matching light/dark appearance.
