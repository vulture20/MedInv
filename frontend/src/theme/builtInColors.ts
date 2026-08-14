import type { TemplateColors } from './ThemeContext'

/**
 * Mirrors the literal color values in frontend/src/index.css's `:root` and
 * `:root[data-template='dark']` blocks. CSS custom properties can't be
 * imported into JS directly (unlike locales/de.json's translations,
 * i18n/index.ts's BUNDLED_TRANSLATIONS), so this is a deliberate, manually-
 * kept-in-sync duplicate — used only for TemplatesPage.tsx's read-only
 * "view"/"download" of the two built-in templates. light/dark themselves
 * are completely unaffected by this file's accuracy: they're still driven
 * purely by index.css's static rules plus the `data-template` attribute
 * (ThemeContext.tsx never applies inline color overrides for a built-in
 * template). If index.css's color values ever change, update this to match.
 */
export const BUILT_IN_TEMPLATE_COLORS: Record<'light' | 'dark', TemplateColors> = {
  light: {
    'color-bg': '#f7f7f8',
    'color-surface': '#ffffff',
    'color-text': '#1a1a1a',
    'color-text-muted': '#6b6b70',
    'color-border': '#e0e0e3',
    'color-accent': '#2f6fed',
    'color-danger': '#c62828',
    'color-danger-bg': '#fdecea',
    'color-scheme': 'light',
  },
  dark: {
    'color-bg': '#16171a',
    'color-surface': '#1f2024',
    'color-text': '#f0f0f2',
    'color-text-muted': '#a0a0a6',
    'color-border': '#2e2f34',
    'color-accent': '#6a9bff',
    'color-danger': '#ff6b6b',
    'color-danger-bg': '#3a1f1f',
    'color-scheme': 'dark',
  },
}
