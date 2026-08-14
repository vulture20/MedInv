import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { useTheme, type Template } from '../../theme/ThemeContext'
import i18n, { AVAILABLE_LANGUAGES } from '../../i18n'
import { getRuntimeLanguagePacks, onRuntimeLanguagePacksChanged, type LanguagePackSummary } from '../../i18n/languagePackEvents'

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
  const { template, setTemplate } = useTheme()
  const [language, setLanguage] = useState(i18n.language)
  // Admin-added packs beyond the bundled de/en (GitHub issues #12/#15) —
  // loaded asynchronously by main.tsx's loadRuntimeLanguagePacks(), which
  // may well finish after this component has already mounted, hence the
  // getRuntimeLanguagePacks() snapshot for the initial value plus the
  // subscription for whenever it (or a later admin edit, see
  // LanguagesPage.tsx) changes.
  const [runtimePacks, setRuntimePacks] = useState<LanguagePackSummary[]>(getRuntimeLanguagePacks)

  useEffect(() => {
    const onLanguageChanged = (lng: string) => setLanguage(lng)
    i18n.on('languageChanged', onLanguageChanged)
    return () => i18n.off('languageChanged', onLanguageChanged)
  }, [])

  useEffect(() => onRuntimeLanguagePacksChanged(setRuntimePacks), [])

  async function save(patch: { preferred_language?: string; preferred_template?: Template }) {
    await apiClient.put('/me/settings', patch)
  }

  return (
    <div>
      <h1>{t('userMenu.settings')}</h1>

      <label>
        {t('settings.template.label')}
        <select
          value={template}
          onChange={(e) => {
            const value = e.target.value as Template
            setTemplate(value)
            void save({ preferred_template: value })
          }}
        >
          <option value="light">{t('settings.template.light')}</option>
          <option value="dark">{t('settings.template.dark')}</option>
        </select>
      </label>

      <label>
        {t('settings.language.label')}
        <select
          value={language}
          onChange={(e) => {
            setLanguage(e.target.value)
            void i18n.changeLanguage(e.target.value)
            void save({ preferred_language: e.target.value })
          }}
        >
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
      </label>
    </div>
  )
}
