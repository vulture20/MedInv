import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'

interface SidebarProps {
  /**
   * Whether the off-canvas panel is expanded on mobile (briefing 11.2). Has
   * no visual effect above the ≤768px breakpoint, where the sidebar's CSS
   * ignores this class and it renders inline as always.
   */
  open?: boolean
}

/**
 * Left sidebar (briefing 11.2): Startseite, Erfassung, Bibliotheken,
 * Statistiken, Administration. "Erfassung" needs write access to at least
 * one library — simplified here to "not a guest" (11.3); a guest never has
 * write access to anything, and a user/admin's actual per-library
 * permissions are re-checked backend-side regardless.
 *
 * The bottom of the sidebar additionally repeats the header user menu's
 * account actions (Benutzereinstellungen/Abmelden), set off with a
 * `sidebar__divider` so they read as a distinct "account" group rather
 * than another item in the main nav list above.
 */
export function Sidebar({ open = false }: SidebarProps) {
  const { t } = useTranslation()
  const { user, logout } = useAuth()

  return (
    <nav id="app-sidebar" className={`sidebar${open ? ' sidebar--open' : ''}`} aria-label="Main navigation">
      <NavLink to="/" end>
        {t('nav.home')}
      </NavLink>
      {user?.level !== 'guest' && <NavLink to="/capture">{t('nav.capture')}</NavLink>}
      <NavLink to="/libraries">{t('nav.libraries')}</NavLink>
      <NavLink to="/statistics">{t('nav.statistics')}</NavLink>
      {user?.level === 'admin' && <NavLink to="/admin">{t('nav.administration')}</NavLink>}

      <hr className="sidebar__divider" />
      <NavLink to="/settings">{t('userMenu.settings')}</NavLink>
      <button type="button" className="sidebar__logout" onClick={() => void logout()}>
        {t('userMenu.logout')}
      </button>
    </nav>
  )
}
