import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import LanguageDetector from 'i18next-browser-languagedetector'
import de from './locales/de.json'
import en from './locales/en.json'

/**
 * Ships with German + English (briefing 11.4/17.). Additional language
 * packs are the same key-value JSON shape as locales/de.json — an admin
 * adds a new file here plus a resources entry; no other code changes
 * needed. Backend enforcement of "only admins may add language packs" is a
 * TODO once the admin UI for it exists (briefing 11.4).
 */
export const AVAILABLE_LANGUAGES = ['de', 'en'] as const

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

export default i18n
