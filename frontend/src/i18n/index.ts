import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import LanguageDetector from 'i18next-browser-languagedetector'
import { apiClient } from '../api/client'
import de from './locales/de.json'
import en from './locales/en.json'

/**
 * Ships with German + English (briefing 11.4/17.). Additional admin-managed
 * language packs are a stated extension point (same key-value JSON shape as
 * locales/de.json) but not implemented yet — see GitHub issues #12/#15.
 */
export const AVAILABLE_LANGUAGES = ['de', 'en'] as const

// Captured *before* i18next-browser-languagedetector's init() below runs its
// own detection — needed by applyAdminDefaultLanguage() to tell "this
// visitor already has an explicit cached language" (real prior match or
// manual choice) apart from "init() is about to write today's *first*
// resolution into the same key", which happens either way and would
// otherwise make the two cases indistinguishable once read afterwards.
const cachedLanguageBeforeInit = typeof window !== 'undefined' ? window.localStorage.getItem('i18nextLng') : null

i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      de: { translation: de },
      en: { translation: en },
    },
    fallbackLng: 'en',
    supportedLngs: AVAILABLE_LANGUAGES as unknown as string[],
    interpolation: { escapeValue: false },
  })

/**
 * Applies the admin-configured default language (briefing 11.4: "eine
 * Standardsprache, falls 'de' und 'en' beide nicht zutreffen") for a
 * visitor whose browser declares neither — i18next's own `fallbackLng`
 * above is hardcoded to 'en' and can't be made to read a runtime setting
 * before its synchronous init() (the app must render immediately, not wait
 * on a network round trip). Called once from main.tsx; failures are
 * swallowed since the hardcoded 'en' fallback already in effect from
 * init() is a fully functional default on its own.
 *
 * Must never override an explicit prior choice — a returning visitor whose
 * browser previously matched 'de'/'en', or who manually picked a language
 * in Settings, always keeps it, even if that no longer matches the current
 * admin default or their current browser language.
 */
export async function applyAdminDefaultLanguage(): Promise<void> {
  if (cachedLanguageBeforeInit) return

  const browserLanguages = navigator.languages ?? [navigator.language]
  if (browserLanguages.some((lng) => /^(de|en)\b/i.test(lng))) return

  try {
    const { data } = await apiClient.get<{ default_language: string }>('/locale')
    await i18n.changeLanguage(data.default_language)
  } catch {
    // Offline backend — the hardcoded 'en' fallback from init() remains in effect.
  }
}

export default i18n
