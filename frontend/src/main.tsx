import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { applyAdminDefaultLanguage, loadRuntimeLanguagePacks } from './i18n'
import './index.css'
import App from './App.tsx'

// Fire-and-forget: must not block the initial render, which already has a
// fully functional language from i18n's synchronous init() (see
// i18n/index.ts). Sequenced, not parallel: the admin-configured default
// language (briefing 11.4) can itself be a runtime language pack's code
// since GitHub issues #12/#15 (AdminSettingsController::updateLocale()) —
// applyAdminDefaultLanguage() must not call i18n.changeLanguage() with a
// pack code that loadRuntimeLanguagePacks() hasn't registered yet, or a
// first-time visitor whose browser matches neither de/en would get stuck
// showing English-fallback text under the "wrong" active language until
// their next reload.
void loadRuntimeLanguagePacks().then(() => applyAdminDefaultLanguage())

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
