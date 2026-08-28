import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { describeError } from './adminErrors'
import { AVAILABLE_LANGUAGES } from '../../i18n'
import { getRuntimeLanguagePacks, onRuntimeLanguagePacksChanged, type LanguagePackSummary } from '../../i18n/languagePackEvents'
import { CURRENCY_CODES, currencyLabel } from '../libraries/mediaItemFields'

interface SecuritySettings {
  throttle_max_attempts: number
  throttle_window_minutes: number
  throttle_lock_minutes: number
}

type LogLevel = 'DEBUG' | 'INFO' | 'WARNING' | 'ERROR'
// Not just 'de' | 'en' — AdminSettingsController::updateLocale() also
// accepts any code with a language_packs row (admin-added or one of the
// repo-shipped languagepacks/*.json packs), same reasoning as
// SettingsPage.tsx's own per-user language <select>.
type DefaultLanguage = string

interface CoverCleanupSettings {
  cleanup_enabled: boolean
}

interface EanEditingSettings {
  enabled: boolean
}

/**
 * The remaining runtime settings that don't have a page of their own:
 * brute-force throttling (briefing 12.4, enforced by BruteForceProtection),
 * the log level, the default language, the daily orphaned-cover-file
 * cleanup toggle, the display timezone used for backup/export filenames
 * (GitHub issue #31), and whether admins may use the media item detail
 * dialog's admin-only EAN editor at all (GitHub issue #202, gating GitHub
 * issue #201's editor). Mail lives on its own page, backup
 * schedule/retention lives with the backups list — see
 * pages/admin/{Mail,Backups}Page.tsx.
 *
 * Card layout matches LibrariesPage.tsx's/StatisticsPage.tsx's (.panel-page/
 * .panel-card/.panel-select/.panel-confirmation, see index.css's shared
 * docblock) — one card per setting group. No .panel-page__header here: this
 * renders inside AdminLayout.tsx's <Outlet/>, which already supplies the
 * "Administration" page title and tab strip shared by every admin/*Page.tsx,
 * so a second, page-level heading here would be redundant.
 */
export function SystemSettingsPage() {
  const { t, i18n } = useTranslation()
  const { refreshEanEditingSetting } = useAuth()
  // GitHub issue #110 — see load()'s own docblock for why this is separate
  // from the six section-specific error states below.
  const [loadError, setLoadError] = useState<string | null>(null)
  const [security, setSecurity] = useState<SecuritySettings | null>(null)
  const [securitySaved, setSecuritySaved] = useState(false)
  const [securityError, setSecurityError] = useState<string | null>(null)
  const [loglevel, setLoglevel] = useState<LogLevel | null>(null)
  const [loglevelSaved, setLoglevelSaved] = useState(false)
  const [loglevelError, setLoglevelError] = useState<string | null>(null)
  const [defaultLanguage, setDefaultLanguage] = useState<DefaultLanguage | null>(null)
  const [localeSaved, setLocaleSaved] = useState(false)
  const [localeError, setLocaleError] = useState<string | null>(null)
  const [coverCleanup, setCoverCleanup] = useState<CoverCleanupSettings | null>(null)
  const [coverCleanupSaved, setCoverCleanupSaved] = useState(false)
  const [coverCleanupError, setCoverCleanupError] = useState<string | null>(null)
  const [eanEditing, setEanEditing] = useState<EanEditingSettings | null>(null)
  const [eanEditingSaved, setEanEditingSaved] = useState(false)
  const [eanEditingError, setEanEditingError] = useState<string | null>(null)
  const [timezone, setTimezone] = useState<string | null>(null)
  // GitHub issue #199 — the backend's own validation list
  // (AdminSettingsController::index()'s `timezone_options`,
  // \DateTimeZone::listIdentifiers()), replacing a previous
  // browser-Intl-sourced list that had no guaranteed parity with what
  // updateTimezone() would actually accept. Empty until load() resolves,
  // same as every other section here — the <select> below is only ever
  // rendered once `timezone` itself is non-null, by which point this has
  // loaded too (both come from the same response).
  const [timezoneOptions, setTimezoneOptions] = useState<string[]>([])
  const [timezoneSaved, setTimezoneSaved] = useState(false)
  const [timezoneError, setTimezoneError] = useState<string | null>(null)
  // GitHub issue #62 (alternative 3). `undefined` is "not loaded yet" (the
  // section below stays hidden, same as every other setting here); once
  // loaded, `null` is the legitimate "not configured" value — the two
  // must stay distinguishable, unlike e.g. `timezone` above, which always
  // has *some* value (a real default) by the time load() resolves.
  const [defaultCurrency, setDefaultCurrency] = useState<string | null | undefined>(undefined)
  const [defaultCurrencySaved, setDefaultCurrencySaved] = useState(false)
  const [defaultCurrencyError, setDefaultCurrencyError] = useState<string | null>(null)
  // Same reasoning as SettingsPage.tsx's runtimePacks: main.tsx's
  // loadRuntimeLanguagePacks() may still be in flight when this page
  // mounts, hence the snapshot-plus-subscription pair rather than a single read.
  const [runtimePacks, setRuntimePacks] = useState<LanguagePackSummary[]>(getRuntimeLanguagePacks)

  /**
   * GitHub issue #110 — previously missing entirely: a failed request left
   * every section below at its initial null, each silently not rendering
   * *at all* (every section, its own error display included, is gated
   * behind e.g. `{security && (...)}`) — so unlike this file's six
   * existing per-section error states (each only ever set by that
   * section's own save() below, and rendered *inside* that same
   * now-hidden section), a failure here needs its own page-level
   * `loadError`, rendered unconditionally, or it would have nowhere
   * visible to appear.
   */
  async function load() {
    setLoadError(null)
    try {
      const { data } = await apiClient.get<{
        security: SecuritySettings
        loglevel: LogLevel
        locale: { default_language: DefaultLanguage }
        covers: CoverCleanupSettings
        ean_editing: EanEditingSettings
        timezone: string
        timezone_options: string[]
        statistics: { default_currency: string | null }
      }>('/admin/settings')
      setSecurity(data.security)
      setLoglevel(data.loglevel)
      setDefaultLanguage(data.locale.default_language)
      setCoverCleanup(data.covers)
      setEanEditing(data.ean_editing)
      setTimezone(data.timezone)
      setTimezoneOptions(data.timezone_options)
      setDefaultCurrency(data.statistics.default_currency)
    } catch (err) {
      setLoadError(describeError(err, t))
    }
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => onRuntimeLanguagePacksChanged(setRuntimePacks), [])

  async function saveSecurity(e: React.FormEvent) {
    e.preventDefault()
    if (!security) return
    setSecurityError(null)
    setSecuritySaved(false)
    try {
      const { data } = await apiClient.put<SecuritySettings>('/admin/settings/security', security)
      setSecurity(data)
      setSecuritySaved(true)
    } catch (err) {
      setSecurityError(describeError(err, t))
    }
  }

  async function saveLoglevel(e: React.FormEvent) {
    e.preventDefault()
    if (!loglevel) return
    setLoglevelError(null)
    setLoglevelSaved(false)
    try {
      const { data } = await apiClient.put<{ loglevel: LogLevel }>('/admin/settings/loglevel', { loglevel })
      setLoglevel(data.loglevel)
      setLoglevelSaved(true)
    } catch (err) {
      setLoglevelError(describeError(err, t))
    }
  }

  async function saveLocale(e: React.FormEvent) {
    e.preventDefault()
    if (!defaultLanguage) return
    setLocaleError(null)
    setLocaleSaved(false)
    try {
      const { data } = await apiClient.put<{ default_language: DefaultLanguage }>('/admin/settings/locale', {
        default_language: defaultLanguage,
      })
      setDefaultLanguage(data.default_language)
      setLocaleSaved(true)
    } catch (err) {
      setLocaleError(describeError(err, t))
    }
  }

  async function saveTimezone(e: React.FormEvent) {
    e.preventDefault()
    if (!timezone) return
    setTimezoneError(null)
    setTimezoneSaved(false)
    try {
      const { data } = await apiClient.put<{ timezone: string }>('/admin/settings/timezone', { timezone })
      setTimezone(data.timezone)
      setTimezoneSaved(true)
    } catch (err) {
      setTimezoneError(describeError(err, t))
    }
  }

  /** GitHub issue #62 (alternative 3) — see StatisticsPage.tsx's currency_mismatch badge for what this setting actually drives. */
  async function saveDefaultCurrency(e: React.FormEvent) {
    e.preventDefault()
    setDefaultCurrencyError(null)
    setDefaultCurrencySaved(false)
    try {
      const { data } = await apiClient.put<{ default_currency: string | null }>('/admin/settings/statistics', {
        default_currency: defaultCurrency?.trim() || null,
      })
      setDefaultCurrency(data.default_currency)
      setDefaultCurrencySaved(true)
    } catch (err) {
      setDefaultCurrencyError(describeError(err, t))
    }
  }

  async function saveCoverCleanup(enabled: boolean) {
    setCoverCleanupError(null)
    setCoverCleanupSaved(false)
    // Optimistic update so the checkbox feels immediate, same pattern as PluginsPage.tsx's enabled toggle.
    setCoverCleanup({ cleanup_enabled: enabled })
    try {
      const { data } = await apiClient.put<CoverCleanupSettings>('/admin/settings/covers', { cleanup_enabled: enabled })
      setCoverCleanup(data)
      setCoverCleanupSaved(true)
    } catch (err) {
      setCoverCleanupError(describeError(err, t))
      await load()
    }
  }

  /** GitHub issue #202 — toggles GitHub issue #201's admin-only EAN editor, same optimistic-checkbox shape as saveCoverCleanup() above. */
  async function saveEanEditing(enabled: boolean) {
    setEanEditingError(null)
    setEanEditingSaved(false)
    setEanEditing({ enabled })
    try {
      const { data } = await apiClient.put<EanEditingSettings>('/admin/settings/ean-editing', { enabled })
      setEanEditing(data)
      setEanEditingSaved(true)
      // So a media item dialog already open elsewhere in this same session
      // (or opened right after, without a full page reload) reflects the
      // change immediately — same reasoning as MailPage.tsx's refreshMailStatus() call.
      void refreshEanEditingSetting()
    } catch (err) {
      setEanEditingError(describeError(err, t))
      await load()
    }
  }

  return (
    <div className="panel-page">
      {loadError && <p role="alert">{loadError}</p>}

      {security && (
        <section className="panel-card">
          <h2>{t('admin.securitySettings.title')}</h2>
          <form onSubmit={saveSecurity}>
            <label>
              {t('admin.securitySettings.maxAttempts')}
              <input
                className="panel-select"
                type="number"
                min={1}
                value={security.throttle_max_attempts}
                onChange={(e) => setSecurity({ ...security, throttle_max_attempts: Number(e.target.value) })}
                required
              />
            </label>
            <label>
              {t('admin.securitySettings.windowMinutes')}
              <input
                className="panel-select"
                type="number"
                min={1}
                value={security.throttle_window_minutes}
                onChange={(e) => setSecurity({ ...security, throttle_window_minutes: Number(e.target.value) })}
                required
              />
            </label>
            <label>
              {t('admin.securitySettings.lockMinutes')}
              <input
                className="panel-select"
                type="number"
                min={1}
                value={security.throttle_lock_minutes}
                onChange={(e) => setSecurity({ ...security, throttle_lock_minutes: Number(e.target.value) })}
                required
              />
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {securitySaved && (
              <p role="status" className="panel-confirmation">
                {t('admin.securitySettings.saved')}
              </p>
            )}
            {securityError && <p role="alert">{securityError}</p>}
          </form>
        </section>
      )}

      {loglevel && (
        <section className="panel-card">
          <h2>{t('admin.logLevel.title')}</h2>
          <form onSubmit={saveLoglevel}>
            <label>
              {t('admin.logLevel.title')}
              <select className="panel-select" value={loglevel} onChange={(e) => setLoglevel(e.target.value as LogLevel)}>
                <option value="DEBUG">DEBUG</option>
                <option value="INFO">INFO</option>
                <option value="WARNING">WARNING</option>
                <option value="ERROR">ERROR</option>
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {loglevelSaved && (
              <p role="status" className="panel-confirmation">
                {t('admin.logLevel.saved')}
              </p>
            )}
            {loglevelError && <p role="alert">{loglevelError}</p>}
          </form>
        </section>
      )}

      {defaultLanguage && (
        <section className="panel-card">
          <h2>{t('admin.localeSettings.title')}</h2>
          <p className="hint">{t('admin.localeSettings.hint')}</p>
          <form onSubmit={saveLocale}>
            <label>
              {t('admin.localeSettings.defaultLanguage')}
              <select className="panel-select" value={defaultLanguage} onChange={(e) => setDefaultLanguage(e.target.value)}>
                {AVAILABLE_LANGUAGES.map((lng) => (
                  <option key={lng} value={lng}>
                    {t(`settings.language.${lng}`)}
                  </option>
                ))}
                {/* Runtime packs have no settings.language.<code> translation
                    key (the code is admin-chosen) — the pack's own `name`
                    (e.g. "Français") is the label instead, same as
                    SettingsPage.tsx's per-user language <select>. */}
                {runtimePacks.map((pack) => (
                  <option key={pack.code} value={pack.code}>
                    {pack.name}
                  </option>
                ))}
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {localeSaved && (
              <p role="status" className="panel-confirmation">
                {t('admin.localeSettings.saved')}
              </p>
            )}
            {localeError && <p role="alert">{localeError}</p>}
          </form>
        </section>
      )}

      {timezone && (
        <section className="panel-card">
          <h2>{t('admin.timezoneSettings.title')}</h2>
          <p className="hint">{t('admin.timezoneSettings.hint')}</p>
          <form onSubmit={saveTimezone}>
            <label>
              {t('admin.timezoneSettings.timezone')}
              <select className="panel-select" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
                {timezoneOptions.map((tz) => (
                  <option key={tz} value={tz}>
                    {tz}
                  </option>
                ))}
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {timezoneSaved && (
              <p role="status" className="panel-confirmation">
                {t('admin.timezoneSettings.saved')}
              </p>
            )}
            {timezoneError && <p role="alert">{timezoneError}</p>}
          </form>
        </section>
      )}

      {defaultCurrency !== undefined && (
        <section className="panel-card">
          <h2>{t('admin.statisticsSettings.title')}</h2>
          <p className="hint">{t('admin.statisticsSettings.hint')}</p>
          <form onSubmit={saveDefaultCurrency}>
            <label>
              {t('admin.statisticsSettings.defaultCurrency')}
              {/* GitHub issue #114 — a fixed <select> (ISO 4217 codes, same CURRENCY_CODES/currencyLabel() mediaItemFields.ts's own currency field uses) instead of a free-text code the admin had to type correctly from memory. */}
              <select className="panel-select" value={defaultCurrency ?? ''} onChange={(e) => setDefaultCurrency(e.target.value)}>
                <option value="">{t('mediaItem.selectValue')}</option>
                {CURRENCY_CODES.map((code) => (
                  <option key={code} value={code}>
                    {currencyLabel(code, i18n.language)}
                  </option>
                ))}
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {defaultCurrencySaved && (
              <p role="status" className="panel-confirmation">
                {t('admin.statisticsSettings.saved')}
              </p>
            )}
            {defaultCurrencyError && <p role="alert">{defaultCurrencyError}</p>}
          </form>
        </section>
      )}

      {coverCleanup && (
        <section className="panel-card">
          <h2>{t('admin.coverCleanup.title')}</h2>
          <p className="hint">{t('admin.coverCleanup.hint')}</p>
          <label>
            <input
              type="checkbox"
              checked={coverCleanup.cleanup_enabled}
              onChange={(e) => void saveCoverCleanup(e.target.checked)}
            />
            {t('admin.coverCleanup.enabled')}
          </label>
          {coverCleanupSaved && (
            <p role="status" className="panel-confirmation">
              {t('admin.coverCleanup.saved')}
            </p>
          )}
          {coverCleanupError && <p role="alert">{coverCleanupError}</p>}
        </section>
      )}

      {eanEditing && (
        <section className="panel-card">
          <h2>{t('admin.eanEditing.title')}</h2>
          <p className="hint">{t('admin.eanEditing.hint')}</p>
          <label>
            <input type="checkbox" checked={eanEditing.enabled} onChange={(e) => void saveEanEditing(e.target.checked)} />
            {t('admin.eanEditing.enabled')}
          </label>
          {eanEditingSaved && (
            <p role="status" className="panel-confirmation">
              {t('admin.eanEditing.saved')}
            </p>
          )}
          {eanEditingError && <p role="alert">{eanEditingError}</p>}
        </section>
      )}
    </div>
  )
}
