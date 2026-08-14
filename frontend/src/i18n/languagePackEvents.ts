/**
 * Direct structural clone of api/authEvents.ts, for the same reason: the
 * thing that changes (i18n/index.ts's runtime language pack registry,
 * outside the React tree) can't call a hook to make a mounted component
 * re-render, so it publishes here instead and interested components (only
 * SettingsPage.tsx's language <select> today) subscribe. A plain listener
 * set plus a last-known snapshot, not a full event-emitter dependency —
 * exactly one event type, and getRuntimeLanguagePacks() covers the case
 * where a component mounts *after* the initial loadRuntimeLanguagePacks()
 * (main.tsx) already ran and published once.
 */
export interface LanguagePackSummary {
  code: string
  name: string
}

type Listener = (packs: LanguagePackSummary[]) => void

const listeners = new Set<Listener>()
let current: LanguagePackSummary[] = []

/** Current snapshot — for a component mounting after the initial load already published. */
export function getRuntimeLanguagePacks(): LanguagePackSummary[] {
  return current
}

/** Returns an unsubscribe function, for use in a useEffect cleanup. */
export function onRuntimeLanguagePacksChanged(listener: Listener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function setRuntimeLanguagePacks(packs: LanguagePackSummary[]): void {
  current = packs
  listeners.forEach((listener) => listener(packs))
}
