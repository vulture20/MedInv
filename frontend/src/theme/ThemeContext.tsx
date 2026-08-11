import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'

/**
 * The two ship-with templates (briefing 10./11.4: "Start: Hell/Dunkel").
 * Extending this to installable third-party templates later means widening
 * this union (or switching to a string keyed against a template registry)
 * — deliberately kept simple for now since only two templates exist.
 */
export type Template = 'light' | 'dark'

interface ThemeContextValue {
  template: Template
  setTemplate: (template: Template) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

/**
 * Applies `data-template` on <html> so CSS can key off it (see index.css).
 * The authoritative value is the logged-in user's `preferred_template`
 * (briefing 4.1); until that's loaded this falls back to localStorage/OS
 * preference so the login screen itself isn't stuck on one theme.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [template, setTemplateState] = useState<Template>(() => {
    const stored = localStorage.getItem('medinv.template')
    if (stored === 'light' || stored === 'dark') return stored
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  })

  useEffect(() => {
    document.documentElement.setAttribute('data-template', template)
    localStorage.setItem('medinv.template', template)
  }, [template])

  return (
    <ThemeContext.Provider value={{ template, setTemplate: setTemplateState }}>
      {children}
    </ThemeContext.Provider>
  )
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within a ThemeProvider')
  return ctx
}
