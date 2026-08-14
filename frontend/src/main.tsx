import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { applyBrowserOrDefaultLanguage, loadRuntimeLanguagePacks } from './i18n'
import './index.css'
import App from './App.tsx'

// Fire-and-forget: must not block the initial render, which already has a
// fully functional language from i18n's synchronous init() (see
// i18n/index.ts). Sequenced, not parallel: applyBrowserOrDefaultLanguage()
// matches the browser's language against every *installed* language,
// bundled or runtime pack (GitHub issues #12/#15) — it must not run before
// loadRuntimeLanguagePacks() has registered those packs with i18next, or it
// could either miss a real match or switch to a pack whose resources
// aren't registered yet, leaving a first-time visitor stuck on
// English-fallback text under the "wrong" active language.
void loadRuntimeLanguagePacks().then(() => applyBrowserOrDefaultLanguage())

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
