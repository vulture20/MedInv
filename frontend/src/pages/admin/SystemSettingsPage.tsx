import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'
import { AVAILABLE_LANGUAGES } from '../../i18n'
import { getRuntimeLanguagePacks, onRuntimeLanguagePacksChanged, type LanguagePackSummary } from '../../i18n/languagePackEvents'

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

/**
 * IANA timezone identifiers, sourced from the browser's own Intl data
 * (same underlying tzdata PHP's DateTimeZone validates against
 * server-side, see AdminSettingsController::updateTimezone()) rather than
 * a hand-maintained list that could drift out of sync or omit a zone the
 * backend would otherwise accept.
 */
const TIMEZONES: string[] = (() => {
  try {
    return Intl.supportedValuesOf('timeZone')
  } catch {
    return ['UTC']
  }
})()

/**
 * The remaining runtime settings that don't have a page of their own:
 * brute-force throttling (briefing 12.4, enforced by BruteForceProtection),
 * the log level, the default language, the daily orphaned-cover-file
 * cleanup toggle, and the display timezone used for backup/export
 * filenames (GitHub issue #31). Mail lives on its own page, backup
 * schedule/retention lives with the backups list — see
 * pages/admin/{Mail,Backups}Page.tsx.
 */
export function SystemSettingsPage() {
  const { t } = useTranslation()
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
  const [timezone, setTimezone] = useState<string | null>(null)
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

  async function load() {
    const { data } = await apiClient.get<{
      security: SecuritySettings
      loglevel: LogLevel
      locale: { default_language: DefaultLanguage }
      covers: CoverCleanupSettings
      timezone: string
      statistics: { default_currency: string | null }
    }>('/admin/settings')
    setSecurity(data.security)
    setLoglevel(data.loglevel)
    setDefaultLanguage(data.locale.default_language)
    setCoverCleanup(data.covers)
    setTimezone(data.timezone)
    setDefaultCurrency(data.statistics.default_currency)
  }

  useEffect(() => {
    void load()
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

  return (
    <>
      {security && (
        <section>
          <h2>{t('admin.securitySettings.title')}</h2>
          <form onSubmit={saveSecurity}>
            <label>
              {t('admin.securitySettings.maxAttempts')}
              <input
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
                type="number"
                min={1}
                value={security.throttle_lock_minutes}
                onChange={(e) => setSecurity({ ...security, throttle_lock_minutes: Number(e.target.value) })}
                required
              />
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {securitySaved && <p role="status">{t('admin.securitySettings.saved')}</p>}
            {securityError && <p role="alert">{securityError}</p>}
          </form>
        </section>
      )}

      {loglevel && (
        <section>
          <h2>{t('admin.logLevel.title')}</h2>
          <form onSubmit={saveLoglevel}>
            <label>
              {t('admin.logLevel.title')}
              <select value={loglevel} onChange={(e) => setLoglevel(e.target.value as LogLevel)}>
                <option value="DEBUG">DEBUG</option>
                <option value="INFO">INFO</option>
                <option value="WARNING">WARNING</option>
                <option value="ERROR">ERROR</option>
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {loglevelSaved && <p role="status">{t('admin.logLevel.saved')}</p>}
            {loglevelError && <p role="alert">{loglevelError}</p>}
          </form>
        </section>
      )}

      {defaultLanguage && (
        <section>
          <h2>{t('admin.localeSettings.title')}</h2>
          <p>{t('admin.localeSettings.hint')}</p>
          <form onSubmit={saveLocale}>
            <label>
              {t('admin.localeSettings.defaultLanguage')}
              <select value={defaultLanguage} onChange={(e) => setDefaultLanguage(e.target.value)}>
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
            {localeSaved && <p role="status">{t('admin.localeSettings.saved')}</p>}
            {localeError && <p role="alert">{localeError}</p>}
          </form>
        </section>
      )}

      {timezone && (
        <section>
          <h2>{t('admin.timezoneSettings.title')}</h2>
          <p className="hint">{t('admin.timezoneSettings.hint')}</p>
          <form onSubmit={saveTimezone}>
            <label>
              {t('admin.timezoneSettings.timezone')}
              <select value={timezone} onChange={(e) => setTimezone(e.target.value)}>
                {TIMEZONES.map((tz) => (
                  <option key={tz} value={tz}>
                    {tz}
                  </option>
                ))}
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {timezoneSaved && <p role="status">{t('admin.timezoneSettings.saved')}</p>}
            {timezoneError && <p role="alert">{timezoneError}</p>}
          </form>
        </section>
      )}

      {defaultCurrency !== undefined && (
        <section>
          <h2>{t('admin.statisticsSettings.title')}</h2>
          <p className="hint">{t('admin.statisticsSettings.hint')}</p>
          <form onSubmit={saveDefaultCurrency}>
            <label>
              {t('admin.statisticsSettings.defaultCurrency')}
              <input
                value={defaultCurrency ?? ''}
                onChange={(e) => setDefaultCurrency(e.target.value)}
                placeholder="EUR"
                maxLength={3}
              />
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {defaultCurrencySaved && <p role="status">{t('admin.statisticsSettings.saved')}</p>}
            {defaultCurrencyError && <p role="alert">{defaultCurrencyError}</p>}
          </form>
        </section>
      )}

      {coverCleanup && (
        <section>
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
          {coverCleanupSaved && <p role="status">{t('admin.coverCleanup.saved')}</p>}
          {coverCleanupError && <p role="alert">{coverCleanupError}</p>}
        </section>
      )}
    </>
  )
}
