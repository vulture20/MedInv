import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useTheme, type Template } from '../../theme/ThemeContext'
import i18n, { AVAILABLE_LANGUAGES } from '../../i18n'
import { getRuntimeLanguagePacks, onRuntimeLanguagePacksChanged, type LanguagePackSummary } from '../../i18n/languagePackEvents'
import { describeError } from '../admin/adminErrors'

/**
 * GET /library-preferences (LibraryPreferenceController::index()) — every
 * library visible to the requesting user, with their own exclude_from_*
 * preference for it (GitHub issue #179, see LibraryUserPreference's own
 * docblock).
 */
interface LibraryPreference {
  library_id: number
  library_name: string
  media_type: 'book' | 'cd' | 'dvd_bluray'
  exclude_from_statistics: boolean
  exclude_from_reports: boolean
  exclude_from_dashboard: boolean
}

/** How long the "Saved" confirmation stays visible after a successful save, before fading back out on its own. */
const SAVED_CONFIRMATION_MS = 2000

/** GitHub issue #194 — mirrors backend/app/Models/User.php's ITEMS_PER_PAGE_OPTIONS; kept in sync by hand, the same as every other backend-defined fixed choice list duplicated on this page (e.g. the 'light'/'dark' template codes above). */
const ITEMS_PER_PAGE_OPTIONS = [20, 50, 100, 200] as const

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
  const { user, deleteAccount } = useAuth()
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
  // GitHub issue #194 — same immediate-save-on-change pattern as
  // template/language above. Initialized from `user.items_per_page` (unlike
  // `language` above, there's no separate live source of truth to prefer
  // over it — MediaItemController::index() is the only consumer, and this
  // page's own PUT is the only writer).
  const [itemsPerPage, setItemsPerPage] = useState(user?.items_per_page ?? 50)
  const [itemsPerPageSaved, setItemsPerPageSaved] = useState(false)
  const [itemsPerPageError, setItemsPerPageError] = useState<string | null>(null)
  const savedTimeouts = useRef<Partial<Record<'template' | 'language' | 'itemsPerPage' | 'password', ReturnType<typeof setTimeout>>>>({})

  // GitHub issue #174 — self-service password change. Unlike template/
  // language above (a single value, saved immediately on change), this is
  // a real multi-field form: current password, new password, confirmation,
  // submitted explicitly, cleared on success rather than re-showing what
  // was just typed.
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [changingPassword, setChangingPassword] = useState(false)
  const [passwordSaved, setPasswordSaved] = useState(false)
  const [passwordError, setPasswordError] = useState<string | null>(null)

  // GitHub issue #179 — exclude_from_statistics/exclude_from_reports were
  // previously a single, global, admin/owner-set toggle per library
  // (GitHub issue #176); now every user sets their own preference for every
  // library visible to them here, plus a new "Von Startseite ausschließen"
  // toggle the Dashboard's random-cover carousels never had before.
  const [libraryPreferences, setLibraryPreferences] = useState<LibraryPreference[]>([])
  const [libraryPreferencesError, setLibraryPreferencesError] = useState<string | null>(null)
  // Per-library, since one row's save can fail (e.g. a stale library since
  // deleted) without that meaning anything about any other row.
  const [preferenceSaveErrors, setPreferenceSaveErrors] = useState<Record<number, string>>({})

  useEffect(() => {
    apiClient
      .get<LibraryPreference[]>('/library-preferences')
      .then(({ data }) => setLibraryPreferences(data))
      .catch((err) => setLibraryPreferencesError(describeError(err, t)))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

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

  function flashSaved(field: 'template' | 'language' | 'itemsPerPage' | 'password') {
    const setSaved =
      field === 'template' ? setTemplateSaved : field === 'language' ? setLanguageSaved : field === 'itemsPerPage' ? setItemsPerPageSaved : setPasswordSaved
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

  /** GitHub issue #174. Fields are cleared on success only — left as typed on failure, so the user can correct e.g. a mistyped confirmation without re-entering the current password too. */
  async function changePassword(e: React.FormEvent) {
    e.preventDefault()
    setPasswordError(null)
    setChangingPassword(true)
    try {
      await apiClient.put('/me/password', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      })
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      flashSaved('password')
    } catch (err) {
      setPasswordError(describeError(err, t))
    } finally {
      setChangingPassword(false)
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

  /** GitHub issue #194 — see ITEMS_PER_PAGE_OPTIONS's own comment for the fixed set of values this is ever called with. */
  async function saveItemsPerPage(value: number) {
    const previous = itemsPerPage
    setItemsPerPage(value)
    setItemsPerPageError(null)
    try {
      await apiClient.put('/me/settings', { items_per_page: value })
      flashSaved('itemsPerPage')
    } catch (err) {
      setItemsPerPage(previous)
      setItemsPerPageError(describeError(err, t))
    }
  }

  /**
   * PUT /libraries/{id}/preference (GitHub issue #179) — same immediate-
   * save-on-change pattern as saveTemplate()/saveLanguage() above, applied
   * one checkbox at a time rather than a whole-row submit so ticking one
   * box never risks clobbering a value already saved for a different box
   * on the same row from a still-in-flight request. Optimistically updates
   * local state, reverting just this one row's field on failure.
   */
  async function togglePreference(libraryId: number, field: keyof Omit<LibraryPreference, 'library_id' | 'library_name' | 'media_type'>, value: boolean) {
    const previous = libraryPreferences
    setLibraryPreferences((prev) => prev.map((p) => (p.library_id === libraryId ? { ...p, [field]: value } : p)))
    setPreferenceSaveErrors((prev) => {
      const next = { ...prev }
      delete next[libraryId]
      return next
    })
    try {
      await apiClient.put(`/libraries/${libraryId}/preference`, { [field]: value })
    } catch (err) {
      setLibraryPreferences(previous)
      setPreferenceSaveErrors((prev) => ({ ...prev, [libraryId]: describeError(err, t) }))
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
        <h2>{t('settings.itemsPerPage.label')}</h2>
        <p className="hint">{t('settings.itemsPerPage.hint')}</p>

        <select className="panel-select" value={itemsPerPage} onChange={(e) => void saveItemsPerPage(Number(e.target.value))}>
          {ITEMS_PER_PAGE_OPTIONS.map((value) => (
            <option key={value} value={value}>
              {value}
            </option>
          ))}
        </select>

        {itemsPerPageSaved && (
          <p role="status" className="panel-confirmation">
            {t('settings.saved')}
          </p>
        )}
        {itemsPerPageError && <p role="alert">{itemsPerPageError}</p>}
      </section>

      <section className="panel-card">
        <h2>{t('settings.libraryPreferences.title')}</h2>
        <p className="hint">{t('settings.libraryPreferences.hint')}</p>

        {libraryPreferencesError && <p role="alert">{libraryPreferencesError}</p>}

        {!libraryPreferencesError && libraryPreferences.length === 0 && <p className="hint">{t('settings.libraryPreferences.none')}</p>}

        {libraryPreferences.length > 0 && (
          <table className="media-item-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('libraries.exclusion.fromStatistics')}</th>
                <th>{t('libraries.exclusion.fromReports')}</th>
                <th>{t('libraries.exclusion.fromDashboard')}</th>
              </tr>
            </thead>
            <tbody>
              {libraryPreferences.map((pref) => (
                <tr key={pref.library_id}>
                  <td>{pref.library_name}</td>
                  <td>
                    <input
                      type="checkbox"
                      checked={pref.exclude_from_statistics}
                      onChange={(e) => void togglePreference(pref.library_id, 'exclude_from_statistics', e.target.checked)}
                      aria-label={t('libraries.exclusion.fromStatistics')}
                    />
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      checked={pref.exclude_from_reports}
                      onChange={(e) => void togglePreference(pref.library_id, 'exclude_from_reports', e.target.checked)}
                      aria-label={t('libraries.exclusion.fromReports')}
                    />
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      checked={pref.exclude_from_dashboard}
                      onChange={(e) => void togglePreference(pref.library_id, 'exclude_from_dashboard', e.target.checked)}
                      aria-label={t('libraries.exclusion.fromDashboard')}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {Object.entries(preferenceSaveErrors).map(([libraryId, message]) => (
          <p role="alert" key={libraryId}>
            {libraryPreferences.find((p) => p.library_id === Number(libraryId))?.library_name}: {message}
          </p>
        ))}
      </section>

      {/* GitHub issue #174 — hidden for an SSO-provisioned account, which
          has no local password its owner could ever know (see
          AccountSettingsController::updatePassword()'s own docblock) —
          showing this form would only ever fail with a confusing "wrong
          password" for a password that was never really theirs to know. */}
      {!user?.oidc_subject && (
        <section className="panel-card">
          <h2>{t('settings.password.label')}</h2>
          <p className="hint">{t('settings.password.hint')}</p>

          <form className="settings-password-form" onSubmit={(e) => void changePassword(e)}>
            <label>
              {t('settings.password.current')}
              <input
                className="panel-select"
                type="password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </label>
            <label>
              {t('settings.password.new')}
              <input
                className="panel-select"
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                autoComplete="new-password"
                required
              />
            </label>
            <label>
              {t('settings.password.confirm')}
              <input
                className="panel-select"
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                autoComplete="new-password"
                required
              />
            </label>
            <p className="hint">{t('admin.passwordHint')}</p>

            <button type="submit" disabled={changingPassword}>
              {t('settings.password.submit')}
            </button>
          </form>

          {passwordSaved && (
            <p role="status" className="panel-confirmation">
              {t('settings.saved')}
            </p>
          )}
          {passwordError && <p role="alert">{passwordError}</p>}
        </section>
      )}

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
