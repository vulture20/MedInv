import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import LanguageDetector from 'i18next-browser-languagedetector'
import { apiClient } from '../api/client'
import { setRuntimeLanguagePacks, type LanguagePackSummary } from './languagePackEvents'
import de from './locales/de.json'
import en from './locales/en.json'

/**
 * Ships with German + English (briefing 11.4/17.). Additional admin-managed
 * language packs (GitHub issues #12/#15) are loaded at runtime on top of
 * these, see loadRuntimeLanguagePacks() below — this tuple stays limited to
 * what's actually bundled into the build.
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

/**
 * Registers one admin-added language pack's translations with the already-
 * running i18next instance (GitHub issues #12/#15) — shared by
 * loadRuntimeLanguagePacks() below and LanguagesPage.tsx, so a pack an admin
 * just created or edited becomes selectable/updated immediately, in this
 * same tab, without a full reload.
 */
export function registerLanguagePack(code: string, translations: object): void {
  i18n.addResourceBundle(code, 'translation', translations, true, true)
  setSupportedLngs([...((i18n.options.supportedLngs as string[] | undefined) ?? []).filter((c) => c !== code), code])
}

/** Counterpart to registerLanguagePack(), used by LanguagesPage.tsx after deleting a pack. */
export function unregisterLanguagePack(code: string): void {
  i18n.removeResourceBundle(code, 'translation')
  setSupportedLngs(((i18n.options.supportedLngs as string[] | undefined) ?? []).filter((c) => c !== code))
}

/**
 * i18next's LanguageUtils service copies `options.supportedLngs` into its
 * own `this.supportedLngs` property once, at construction time (i18n.init()
 * above) — it never re-reads `i18n.options.supportedLngs` afterwards.
 * Writing `i18n.options.supportedLngs = [...]` alone (what an earlier
 * version of this function did) therefore silently has no effect on actual
 * language resolution: i18n.changeLanguage(code) still updates the
 * `i18n.language` string, but t() then resolves through
 * services.languageUtils.toResolveHierarchy(), which still treats `code` as
 * unsupported and falls through to a different lookup chain — a real bug
 * caught live (Playwright showed i18n.language === 'fr' with a correctly
 * registered 'fr' resource bundle, yet t() kept returning the German text).
 * i18n.services.languageUtils isn't part of the public TS surface (typed
 * `any`), but is the only way to actually update this at runtime.
 */
function setSupportedLngs(codes: string[]): void {
  i18n.options.supportedLngs = codes
  const languageUtils = (i18n.services as { languageUtils?: { supportedLngs?: string[] } }).languageUtils
  if (languageUtils) languageUtils.supportedLngs = codes
}

/**
 * Loads every admin-added language pack (briefing 11.4/17., GitHub issues
 * #12/#15) and registers it with i18next. Called once, fire-and-forget,
 * from main.tsx, deliberately *after* i18n.init() above — init() must stay
 * synchronous so the app renders immediately with a fully functional
 * (bundled) language; this only adds to that once the network round trip
 * completes. Failures are swallowed per-pack and for the list request
 * itself (an offline/misconfigured backend just means no runtime packs —
 * bundled de/en remain fully usable either way).
 */
export async function loadRuntimeLanguagePacks(): Promise<void> {
  let summaries: LanguagePackSummary[]
  try {
    const { data } = await apiClient.get<LanguagePackSummary[]>('/languages')
    summaries = data
  } catch {
    return
  }

  const loaded: LanguagePackSummary[] = []
  await Promise.all(
    summaries.map(async (pack) => {
      try {
        const { data } = await apiClient.get<{ code: string; translations: object }>(`/languages/${pack.code}`)
        registerLanguagePack(data.code, data.translations)
        loaded.push(pack)
      } catch {
        // One bad/unreachable pack shouldn't take the others down.
      }
    }),
  )
  setRuntimeLanguagePacks(loaded)

  // localStorage trap (i18next-browser-languagedetector caches the last
  // resolved language in localStorage['i18nextLng']): if a returning
  // visitor's cached choice is one of these runtime packs, it didn't exist
  // yet at init()'s synchronous point in time, so i18next silently fell
  // back to fallbackLng in the meantime. Now that the pack is registered,
  // explicitly re-apply it — otherwise that visitor sees English/German
  // instead of the runtime language they actually picked, with no
  // indication anything went wrong.
  if (cachedLanguageBeforeInit && i18n.language !== cachedLanguageBeforeInit) {
    const nowAvailable = loaded.some((pack) => pack.code === cachedLanguageBeforeInit)
    if (nowAvailable) await i18n.changeLanguage(cachedLanguageBeforeInit)
  }
}

export default i18n
