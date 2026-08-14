import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { applyAdminDefaultLanguage, loadRuntimeLanguagePacks } from './i18n'
import './index.css'
import App from './App.tsx'

// Fire-and-forget: must not block the initial render, which already has a
// fully functional language from i18n's synchronous init() (see i18n/index.ts).
void applyAdminDefaultLanguage()
void loadRuntimeLanguagePacks()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
