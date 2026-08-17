import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useTheme, type Template } from '../../theme/ThemeContext'
import i18n, { AVAILABLE_LANGUAGES } from '../../i18n'
import { getRuntimeLanguagePacks, onRuntimeLanguagePacksChanged, type LanguagePackSummary } from '../../i18n/languagePackEvents'
import { describeError } from '../admin/adminErrors'

/** How long the "Saved" confirmation stays visible after a successful save, before fading back out on its own. */
const SAVED_CONFIRMATION_MS = 2000

interface PreviewColors {
  bg: string
  surface: string
  border: string
  accent: string
}

/** index.css's own :root token values — the two templates every deployment always has, so their preview is hardcoded rather than extracted (see extractPreviewColors() below for why runtime templates go through that path instead). Keep in sync by hand if index.css's tokens ever change. */
const BUILT_IN_PREVIEW_COLORS: Record<'light' | 'dark', PreviewColors> = {
  light: { bg: '#f7f7f8', surface: '#ffffff', border: '#e0e0e3', accent: '#2f6fed' },
  dark: { bg: '#16171a', surface: '#1f2024', border: '#2e2f34', accent: '#6a9bff' },
}

function extractCssCustomProperty(css: string, name: string): string | null {
  const match = css.match(new RegExp(`${name}\\s*:\\s*([^;}]+)`))
  return match ? match[1].trim() : null
}

/**
 * Pulls a runtime template's own preview colors straight out of its raw
 * CSS text via a plain regex, so it can get the same live swatch preview
 * light/dark already have instead of a generic placeholder. Reads the same
 * four --color-* custom properties TemplatesPage.tsx's own CSS hint text
 * documents a template as expected to redefine (--color-bg, --color-
 * surface, --color-border, --color-accent, alongside --color-text/
 * --color-text-muted/--color-danger/--color-danger-bg, which this preview
 * doesn't need). Deliberately not a full CSS parser, and deliberately not
 * applied to the live page to read the values back out via
 * getComputedStyle() — that would mean injecting a template's arbitrary,
 * admin-authored CSS just to preview it, before anyone has actually chosen
 * it. Null whenever any of the four is missing (e.g. a template that
 * doesn't redefine one of them, or one that sets it some other way this
 * regex doesn't recognize) — the caller falls back to a plain placeholder
 * rather than show a half-populated or misleading preview.
 */
function extractPreviewColors(css: string): PreviewColors | null {
  const bg = extractCssCustomProperty(css, '--color-bg')
  const surface = extractCssCustomProperty(css, '--color-surface')
  const border = extractCssCustomProperty(css, '--color-border')
  const accent = extractCssCustomProperty(css, '--color-accent')

  return bg && surface && border && accent ? { bg, surface, border, accent } : null
}

/** The live miniature of the actual app chrome (header + sidebar + accent) shared by every swatch that has real colors to show — built-in light/dark always do, a runtime template does whenever extractPreviewColors() found all four. */
function ThemeSwatchPreview({ colors }: { colors: PreviewColors }) {
  return (
    <span className="theme-swatch__preview" style={{ background: colors.bg }}>
      <span className="theme-swatch__preview-header" style={{ background: colors.surface, borderColor: colors.border }} />
      <span className="theme-swatch__preview-sidebar" style={{ background: colors.surface, borderColor: colors.border }} />
      <span className="theme-swatch__preview-accent" style={{ background: colors.accent }} />
    </span>
  )
}

/**
 * The logged-in user's own preferences (briefing 4.1, "benutzerdefinierte
 * Einstellungen"). The language select's value is driven by `i18n.language`
 * itself — the actually-active UI language — rather than the (possibly
 * stale) `user.preferred_language` from the last /me fetch: navigating away
 * from this page and back previously remounted the component with
 * `user.preferred_language` as the initial value, which still said "de"
 * even after switching to English, since nothing ever refreshed the user
 * object after saving. Reading straight from i18n avoids that mismatch
 * entirely instead of trying to keep two sources of truth in sync.
 */
export function SettingsPage() {
  const { t } = useTranslation()
  const { deleteAccount } = useAuth()
  const { template, setTemplate, runtimeTemplates } = useTheme()
  const [deletingAccount, setDeletingAccount] = useState(false)
  const [deleteAccountError, setDeleteAccountError] = useState<string | null>(null)
  const [language, setLanguage] = useState(i18n.language)
  // Admin-added packs beyond the bundled de/en (GitHub issues #12/#15) —
  // loaded asynchronously by main.tsx's loadRuntimeLanguagePacks(), which
  // may well finish after this component has already mounted, hence the
  // getRuntimeLanguagePacks() snapshot for the initial value plus the
  // subscription for whenever it (or a later admin edit, see
  // LanguagesPage.tsx) changes.
  const [runtimePacks, setRuntimePacks] = useState<LanguagePackSummary[]>(getRuntimeLanguagePacks)

  // Save feedback — previously this page had none at all: a failed PUT
  // (e.g. a runtime template deleted by an admin moments before this
  // request) silently left the UI showing a choice that never actually
  // persisted, with nothing to tell the user that happened.
  const [templateSaved, setTemplateSaved] = useState(false)
  const [templateError, setTemplateError] = useState<string | null>(null)
  const [languageSaved, setLanguageSaved] = useState(false)
  const [languageError, setLanguageError] = useState<string | null>(null)
  const savedTimeouts = useRef<Partial<Record<'template' | 'language', ReturnType<typeof setTimeout>>>>({})

  useEffect(() => {
    const onLanguageChanged = (lng: string) => setLanguage(lng)
    i18n.on('languageChanged', onLanguageChanged)
    return () => i18n.off('languageChanged', onLanguageChanged)
  }, [])

  useEffect(() => onRuntimeLanguagePacksChanged(setRuntimePacks), [])

  // Timers are cleared, never left to fire after unmount — this page is
  // small enough to navigate away from quickly right after changing a
  // setting. Reads `.current` once, at effect-setup time, into a variable
  // the cleanup closes over — `savedTimeouts.current` is only ever mutated
  // in place (flashSaved() below), never reassigned, but this is the
  // pattern React's own linter expects regardless.
  useEffect(() => {
    const timeouts = savedTimeouts.current
    return () => {
      Object.values(timeouts).forEach((id) => clearTimeout(id))
    }
  }, [])

  function flashSaved(field: 'template' | 'language') {
    const setSaved = field === 'template' ? setTemplateSaved : setLanguageSaved
    clearTimeout(savedTimeouts.current[field])
    setSaved(true)
    savedTimeouts.current[field] = setTimeout(() => setSaved(false), SAVED_CONFIRMATION_MS)
  }

  async function saveTemplate(value: Template) {
    const previous = template
    setTemplate(value)
    setTemplateError(null)
    try {
      await apiClient.put('/me/settings', { preferred_template: value })
      flashSaved('template')
    } catch (err) {
      setTemplate(previous)
      setTemplateError(describeError(err, t))
    }
  }

  /**
   * Self-service account deletion (GitHub issue #86, briefing 4.1
   * "benutzerdefinierte Einstellungen"). No success-path cleanup of
   * `deletingAccount`/navigation call needed — AuthContext's
   * deleteAccount() already clears `user`, and RequireAuth.tsx redirects to
   * /login the moment that happens, same as an ordinary logout.
   */
  async function handleDeleteAccount() {
    if (!window.confirm(t('settings.deleteAccount.confirm'))) return
    setDeleteAccountError(null)
    setDeletingAccount(true)
    try {
      await deleteAccount()
    } catch (err) {
      setDeleteAccountError(describeError(err, t))
      setDeletingAccount(false)
    }
  }

  async function saveLanguage(value: string) {
    const previous = language
    setLanguage(value)
    void i18n.changeLanguage(value)
    setLanguageError(null)
    try {
      await apiClient.put('/me/settings', { preferred_language: value })
      flashSaved('language')
    } catch (err) {
      setLanguage(previous)
      void i18n.changeLanguage(previous)
      setLanguageError(describeError(err, t))
    }
  }

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('userMenu.settings')}</h1>
        <p className="hint">{t('settings.subtitle')}</p>
      </header>

      <section className="panel-card">
        <h2>{t('settings.template.label')}</h2>
        <p className="hint">{t('settings.template.hint')}</p>

        <div className="theme-swatches" role="radiogroup" aria-label={t('settings.template.label')}>
          {(['light', 'dark'] as const).map((code) => (
            <label key={code} className={`theme-swatch${template === code ? ' theme-swatch--selected' : ''}`}>
              <ThemeSwatchPreview colors={BUILT_IN_PREVIEW_COLORS[code]} />
              <span className="theme-swatch__row">
                <span className="theme-swatch__label">{t(`settings.template.${code}`)}</span>
                <input type="radio" name="template" value={code} checked={template === code} onChange={() => void saveTemplate(code)} />
              </span>
            </label>
          ))}

          {/* Runtime templates (GitHub issue #11) get the same live preview as light/dark
              whenever their CSS redefines all four --color-* properties extractPreviewColors()
              looks for; otherwise a neutral placeholder swatch plus the template's own name is
              the honest amount of preview to offer without parsing/guessing the rest. */}
          {runtimeTemplates.map((tpl) => {
            const colors = extractPreviewColors(tpl.css)

            return (
              <label key={tpl.code} className={`theme-swatch${template === tpl.code ? ' theme-swatch--selected' : ''}`}>
                {colors ? (
                  <ThemeSwatchPreview colors={colors} />
                ) : (
                  <span className="theme-swatch__preview theme-swatch__preview--custom" aria-hidden="true">
                    ✦
                  </span>
                )}
                <span className="theme-swatch__row">
                  <span className="theme-swatch__label">{tpl.name}</span>
                  <input type="radio" name="template" value={tpl.code} checked={template === tpl.code} onChange={() => void saveTemplate(tpl.code)} />
                </span>
              </label>
            )
          })}
        </div>

        {templateSaved && (
          <p role="status" className="panel-confirmation">
            {t('settings.saved')}
          </p>
        )}
        {templateError && <p role="alert">{templateError}</p>}
      </section>

      <section className="panel-card">
        <h2>{t('settings.language.label')}</h2>
        <p className="hint">{t('settings.language.hint')}</p>

        <select className="panel-select" value={language} onChange={(e) => void saveLanguage(e.target.value)}>
          {AVAILABLE_LANGUAGES.map((lng) => (
            <option key={lng} value={lng}>
              {t(`settings.language.${lng}`)}
            </option>
          ))}
          {/* Runtime packs have no settings.language.<code> translation key
              (the code is admin-chosen, not known ahead of time) — the
              pack's own `name` (e.g. "Français") is the label instead. */}
          {runtimePacks.map((pack) => (
            <option key={pack.code} value={pack.code}>
              {pack.name}
            </option>
          ))}
        </select>

        {languageSaved && (
          <p role="status" className="panel-confirmation">
            {t('settings.saved')}
          </p>
        )}
        {languageError && <p role="alert">{languageError}</p>}
      </section>

      <section className="panel-card">
        <h2>{t('settings.deleteAccount.title')}</h2>
        <p className="hint">{t('settings.deleteAccount.hint')}</p>

        <button type="button" disabled={deletingAccount} onClick={() => void handleDeleteAccount()}>
          {t('settings.deleteAccount.button')}
        </button>

        {deleteAccountError && <p role="alert">{deleteAccountError}</p>}
      </section>
    </div>
  )
}
