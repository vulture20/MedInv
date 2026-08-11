import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'

/**
 * Left sidebar (briefing 11.2): Startseite, Erfassung, Bibliotheken,
 * Statistiken, Administration. "Erfassung" needs write access to at least
 * one library — simplified here to "not a guest" (11.3); a guest never has
 * write access to anything, and a user/admin's actual per-library
 * permissions are re-checked backend-side regardless.
 */
export function Sidebar() {
  const { t } = useTranslation()
  const { user } = useAuth()

  return (
    <nav className="sidebar" aria-label="Main navigation">
      <NavLink to="/" end>
        {t('nav.home')}
      </NavLink>
      {user?.level !== 'guest' && <NavLink to="/capture">{t('nav.capture')}</NavLink>}
      <NavLink to="/libraries">{t('nav.libraries')}</NavLink>
      <NavLink to="/statistics">{t('nav.statistics')}</NavLink>
      {user?.level === 'admin' && <NavLink to="/admin">{t('nav.administration')}</NavLink>}
    </nav>
  )
}
