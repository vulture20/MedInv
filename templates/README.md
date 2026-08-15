# Bundled UI templates

Mirrors [`languagepacks/`](../languagepacks/) at the project root, but for
UI themes instead of translations (briefing 10./11.4, GitHub issue #11).
Any `*.json` file placed here is picked up by
`App\Domain\Templates\BundledTemplateRegistry` and pre-installed
automatically on the next boot (`DatabaseSeeder`, safe to run repeatedly —
it never overwrites a template an admin has since edited), and is always
available for an admin to (re)install on demand from the "Templates" admin
page.

The two ship-with templates (`light`/`dark`) are compiled directly into
`frontend/src/index.css`'s static `:root` / `:root[data-template='dark']`
rules, not files here — those two codes are in fact reserved and rejected
if you try to add a `light.json` or `dark.json` here
(`TemplateController::store()`). This directory is for *additional*
templates beyond the two bundled ones, the same way `languagepacks/` never
contains `de.json`/`en.json`.

## Format

```json
{
  "code": "high-contrast",
  "name": "High Contrast",
  "css": ":root {\n  --color-bg: #ffffff;\n  --color-surface: #ffffff;\n  --color-text: #000000;\n  --color-text-muted: #000000;\n  --color-border: #000000;\n  --color-accent: #0000ee;\n  --color-danger: #b00020;\n  --color-danger-bg: #ffffff;\n  color-scheme: light;\n}\n"
}
```

`css` is the literal text content of a real CSS file — not a fixed set of
color values. `frontend/src/theme/ThemeContext.tsx` injects it verbatim
into a single `<style>` element (via `.textContent`, appended to `<head>`
after the app's own stylesheet) whenever this template is selected, and
clears it again when switching away — so a template is free to define
anything a stylesheet can, not just colors.

In practice, the recommended (and by far most common) shape is exactly the
example above: a single `:root { ... }` block redefining the same
`--color-*` custom properties `index.css`'s built-in `:root` block does
(plus `color-scheme`, a real CSS property rather than a custom one, which
is what gives native browser UI — scrollbars, form controls — a matching
light/dark appearance). Every built-in component already reads its colors
exclusively from these properties, so a template that only redefines them
already reskins the entire app consistently, with no risk of leaving some
corner of the UI unstyled. Nothing stops a template from going further —
targeting a specific class, changing fonts, radii, spacing — but the
`:root` block is what makes a template look intentional rather than
half-finished if that's all it sets.

Because the CSS is injected via `.textContent` (never `.innerHTML`), its
content can never break out of the `<style>` element into markup or script
regardless of what it contains — there is no way for a template, however
adversarial, to become anything other than CSS once applied. There is
deliberately no server-side schema validation of `css`'s *content* beyond
"non-empty string within a sane size limit" (`TemplateController`'s
`MAX_CSS_LENGTH`) — unlike the fixed-shape `colors` map this feature used
to have, an unusual or incomplete stylesheet doesn't corrupt anything, it
just styles less (or differently) than a full one would, which is left
entirely to whoever wrote it.
