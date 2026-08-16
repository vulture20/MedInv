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
 * The single <style> element every runtime template's CSS is injected
 * into/cleared from. One shared element (created lazily, reused across
 * template switches) rather than one per template — only ever one
 * template is active at a time, so there's nothing to gain from keeping
 * an inactive template's <style> element around, and a single element
 * keeps "what CSS is currently affecting the page" trivially inspectable
 * in devtools.
 */
const STYLE_ELEMENT_ID = 'medinv-runtime-template'

function getOrCreateStyleElement(): HTMLStyleElement {
  let el = document.getElementById(STYLE_ELEMENT_ID) as HTMLStyleElement | null
  if (!el) {
    el = document.createElement('style')
    el.id = STYLE_ELEMENT_ID
    // Appended to <head>, which places it after the app's own stylesheet
    // (already present by the time this runs, since it's loaded before any
    // React code executes) — later in document order wins the cascade for
    // equal-specificity selectors, e.g. a template's `:root { ... }` block
    // against index.css's own `:root { ... }` block.
    document.head.appendChild(el)
  }
  return el
}

/**
 * Applies one template's CSS by setting it as the shared <style> element's
 * text content. Deliberately `.textContent`, never `.innerHTML`: setting
 * textContent on an existing DOM node is never re-parsed as HTML, so
 * whatever a template's CSS contains — including a literal `</style>`
 * substring — can never break out into markup or script, regardless of who
 * authored it (this data ultimately comes from an admin, but "an admin
 * you trust with theming" shouldn't have to also be trusted with the
 * entire page).
 */
function applyCss(css: string): void {
  getOrCreateStyleElement().textContent = css
}

function clearCss(): void {
  getOrCreateStyleElement().textContent = ''
}

export interface TemplateSummary {
  code: string
  name: string
}

/**
 * `runtimeTemplates` below carries the CSS too, unlike TemplateSummary —
 * GET /templates itself is deliberately a lightweight code+name-only
 * listing (TemplatesPage.tsx fetches that directly for its own list), but
 * ThemeProvider already has to load every runtime template's full CSS
 * anyway (to be able to apply it the moment it's selected), so exposing it
 * here costs nothing extra and lets SettingsPage.tsx's swatch preview
 * (GitHub issue: "Vorschau... analog zu hell/dunkel") read the same
 * `--color-*` custom properties out of it that light/dark preview with
 * hardcoded values.
 */
export interface RuntimeTemplate extends TemplateSummary {
  css: string
}

interface ThemeContextValue {
  template: Template
  setTemplate: (template: Template) => void
  /** Runtime-installed templates only (light/dark are always implicitly available, not listed here). */
  runtimeTemplates: RuntimeTemplate[]
  /**
   * Registers one runtime template's CSS, applying it immediately if it's
   * the currently active one — shared by the initial GET /templates load
   * below and TemplatesPage.tsx, so a template an admin just
   * created/edited/(re)installed takes effect in this same tab without a
   * full reload, same pattern as i18n/index.ts's registerLanguagePack().
   */
  registerTemplate: (code: string, name: string, css: string) => void
  /** Counterpart to registerTemplate(), used by TemplatesPage.tsx after deleting a template. */
  unregisterTemplate: (code: string) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

/**
 * Applies `data-template` on <html> so CSS can key off it (see index.css).
 * The authoritative value is the logged-in user's `preferred_template`
 * (briefing 4.1); until that's loaded this falls back to localStorage/OS
 * preference so the login screen itself isn't stuck on one theme.
 *
 * 'light'/'dark' keep working exactly as before this ever existed — purely
 * via the `data-template` attribute plus index.css's static rules, no JS-
 * applied CSS involved. A runtime template (GitHub issue #11) is the one
 * addition: its CSS lives only in the `templates` database table, so
 * there's no static CSS rule for it to key off — instead, whenever
 * `template` resolves to a runtime code, its CSS is injected verbatim into
 * a dedicated <style> element (applyCss()), which takes effect regardless
 * of whether any `[data-template='<code>']` CSS block exists at all.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [template, setTemplateState] = useState<Template>(() => {
    const stored = localStorage.getItem('medinv.template')
    if (stored) return stored
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  })
  // code -> {name, css} for every runtime template currently known —
  // loaded once on mount (GET /templates(/{code})) and kept up to date
  // in-place by registerTemplate()/unregisterTemplate() afterwards.
  const [templates, setTemplates] = useState<Record<string, { name: string; css: string }>>({})

  useEffect(() => {
    document.documentElement.setAttribute('data-template', template)
    localStorage.setItem('medinv.template', template)

    if (BUILT_IN_TEMPLATES.includes(template)) {
      clearCss()
    } else {
      const entry = templates[template]
      if (entry) applyCss(entry.css)
      // else: css not loaded yet (e.g. a runtime template chosen on a
      // previous visit, before this tab's GET /templates round trip
      // completes) — the previously active template's <style> content
      // simply remains in place a moment longer, rather than flashing to
      // an unstyled/default look; the effect re-runs once templates updates.
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
          const { data } = await apiClient.get<{ code: string; name: string; css: string }>(`/templates/${summary.code}`)
          registerTemplate(data.code, data.name, data.css)
        } catch {
          // One bad/unreachable template shouldn't take the others down.
        }
      }),
    )
  }

  function registerTemplate(code: string, name: string, css: string): void {
    setTemplates((prev) => ({ ...prev, [code]: { name, css } }))
  }

  function unregisterTemplate(code: string): void {
    setTemplates((prev) => {
      const next = { ...prev }
      delete next[code]
      return next
    })
  }

  const runtimeTemplates: RuntimeTemplate[] = Object.entries(templates).map(([code, { name, css }]) => ({ code, name, css }))

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
