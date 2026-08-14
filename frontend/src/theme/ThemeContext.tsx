import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { apiClient } from '../api/client'

/**
 * The two ship-with templates (briefing 10./11.4: "Start: Hell/Dunkel") are
 * 'light'/'dark' by convention, but any other string is a valid, runtime-
 * installed template too (GitHub issue #11) — see runtimeTemplates below.
 * Kept as a plain string rather than a union since the actual set of valid
 * codes is only known at runtime, from the backend's `templates` table.
 */
export type Template = string

const BUILT_IN_TEMPLATES = ['light', 'dark']

/**
 * The exact CSS custom-property names (minus the leading `--`) every
 * template must define — matches backend `App\Models\Template::
 * REQUIRED_COLOR_KEYS` and index.css's `:root`/`:root[data-template='dark']`
 * blocks one-for-one. `color-scheme` is the one non-`--`-prefixed entry (a
 * real CSS property, not a custom property, but set the same way via
 * style.setProperty) — it's what gives native browser UI (scrollbars, form
 * controls) an OS-native dark appearance instead of light-themed chrome
 * drawn over a dark page.
 */
export interface TemplateColors {
  'color-bg': string
  'color-surface': string
  'color-text': string
  'color-text-muted': string
  'color-border': string
  'color-accent': string
  'color-danger': string
  'color-danger-bg': string
  'color-scheme': string
}

/** Exported so TemplatesPage.tsx can build its color-picker form from the same single list, instead of a second hand-maintained copy. */
export const COLOR_KEYS = [
  'color-bg',
  'color-surface',
  'color-text',
  'color-text-muted',
  'color-border',
  'color-accent',
  'color-danger',
  'color-danger-bg',
  'color-scheme',
] as const satisfies readonly (keyof TemplateColors)[]

export interface TemplateSummary {
  code: string
  name: string
}

interface ThemeContextValue {
  template: Template
  setTemplate: (template: Template) => void
  /** Runtime-installed templates only (light/dark are always implicitly available, not listed here). */
  runtimeTemplates: TemplateSummary[]
  /**
   * Registers one runtime template's colors, applying them immediately if
   * it's the currently active one — shared by the initial GET /templates
   * load below and TemplatesPage.tsx, so a template an admin just
   * created/edited/(re)installed takes effect in this same tab without a
   * full reload, same pattern as i18n/index.ts's registerLanguagePack().
   */
  registerTemplate: (code: string, name: string, colors: TemplateColors) => void
  /** Counterpart to registerTemplate(), used by TemplatesPage.tsx after deleting a template. */
  unregisterTemplate: (code: string) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

/** Applies one template's colors as inline CSS custom properties on <html> — inline always wins over any stylesheet rule. */
function applyColors(colors: TemplateColors): void {
  for (const key of COLOR_KEYS) {
    document.documentElement.style.setProperty(`--${key}`, colors[key])
  }
}

/** Removes any inline override, letting the static light/dark CSS rules (index.css) take back over. */
function clearInlineColors(): void {
  for (const key of COLOR_KEYS) {
    document.documentElement.style.removeProperty(`--${key}`)
  }
}

/**
 * Applies `data-template` on <html> so CSS can key off it (see index.css).
 * The authoritative value is the logged-in user's `preferred_template`
 * (briefing 4.1); until that's loaded this falls back to localStorage/OS
 * preference so the login screen itself isn't stuck on one theme.
 *
 * 'light'/'dark' keep working exactly as before this ever existed — purely
 * via the `data-template` attribute plus index.css's static rules, no JS-
 * applied colors involved. A runtime template (GitHub issue #11) is the
 * one addition: its colors live only in the `templates` database table, so
 * there's no static CSS rule for it to key off — instead, whenever `template`
 * resolves to a runtime code, its colors are applied directly as inline
 * custom properties (applyColors()), which take effect regardless of
 * whether any `[data-template='<code>']` CSS block exists at all.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [template, setTemplateState] = useState<Template>(() => {
    const stored = localStorage.getItem('medinv.template')
    if (stored) return stored
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  })
  // code -> {name, colors} for every runtime template currently known —
  // loaded once on mount (GET /templates(/{code})) and kept up to date
  // in-place by registerTemplate()/unregisterTemplate() afterwards.
  const [templates, setTemplates] = useState<Record<string, { name: string; colors: TemplateColors }>>({})

  useEffect(() => {
    document.documentElement.setAttribute('data-template', template)
    localStorage.setItem('medinv.template', template)

    if (BUILT_IN_TEMPLATES.includes(template)) {
      clearInlineColors()
    } else {
      const entry = templates[template]
      if (entry) applyColors(entry.colors)
      // else: colors not loaded yet (e.g. a runtime template chosen on a
      // previous visit, before this tab's GET /templates round trip
      // completes) — the previously active template's inline properties
      // simply remain in place a moment longer, rather than flashing to an
      // unstyled/default look; the effect re-runs once templates updates.
    }
  }, [template, templates])

  useEffect(() => {
    void loadRuntimeTemplates()
  }, [])

  /**
   * Fire-and-forget, mirroring i18n/index.ts's loadRuntimeLanguagePacks():
   * failures are swallowed since built-in light/dark remain fully usable
   * on their own — an offline/misconfigured backend just means no runtime
   * templates are selectable yet.
   */
  async function loadRuntimeTemplates(): Promise<void> {
    let summaries: TemplateSummary[]
    try {
      const { data } = await apiClient.get<TemplateSummary[]>('/templates')
      summaries = data
    } catch {
      return
    }

    await Promise.all(
      summaries.map(async (summary) => {
        try {
          const { data } = await apiClient.get<{ code: string; name: string; colors: TemplateColors }>(`/templates/${summary.code}`)
          registerTemplate(data.code, data.name, data.colors)
        } catch {
          // One bad/unreachable template shouldn't take the others down.
        }
      }),
    )
  }

  function registerTemplate(code: string, name: string, colors: TemplateColors): void {
    setTemplates((prev) => ({ ...prev, [code]: { name, colors } }))
  }

  function unregisterTemplate(code: string): void {
    setTemplates((prev) => {
      const next = { ...prev }
      delete next[code]
      return next
    })
  }

  const runtimeTemplates: TemplateSummary[] = Object.entries(templates).map(([code, { name }]) => ({ code, name }))

  return (
    <ThemeContext.Provider
      value={{ template, setTemplate: setTemplateState, runtimeTemplates, registerTemplate, unregisterTemplate }}
    >
      {children}
    </ThemeContext.Provider>
  )
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within a ThemeProvider')
  return ctx
}
