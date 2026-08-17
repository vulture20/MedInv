import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import { Logo } from '../components/Logo'

interface HeaderProps {
  /** Whether the mobile off-canvas sidebar is currently expanded (briefing 11.2). */
  sidebarOpen: boolean
  /** Toggles the mobile sidebar; the button itself is hidden above the ≤768px breakpoint. */
  onToggleSidebar: () => void
}

/**
 * Top header (briefing 11.2): logo + app name + search on the left;
 * capture quick-access + user menu on the right. The statistics and
 * administration quick-access icons that used to sit here were removed
 * (they duplicated the sidebar's own "Statistiken"/"Administration"
 * entries, which remain the only way to reach those pages) to keep this
 * bar focused; the user menu's "Benutzereinstellungen"/"Abmelden" entries
 * are likewise duplicated at the bottom of the sidebar (see Sidebar.tsx)
 * but were deliberately left here too, as a second, always-reachable path
 * to them regardless of viewport/scroll position.
 */
export function Header({ sidebarOpen, onToggleSidebar }: HeaderProps) {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [menuOpen, setMenuOpen] = useState(false)

  function submitSearch(e: React.FormEvent) {
    e.preventDefault()
    if (query.trim()) navigate(`/search?query=${encodeURIComponent(query.trim())}`)
  }

  return (
    <header className="app-header">
      <div className="app-header__left">
        <button
          type="button"
          className="sidebar-toggle"
          aria-label={t('nav.toggleMenu')}
          aria-expanded={sidebarOpen}
          aria-controls="app-sidebar"
          onClick={onToggleSidebar}
        >
          ☰
        </button>
        <Link to="/" className="app-header__brand">
          <Logo size={28} />
        </Link>
        <form onSubmit={submitSearch} role="search">
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('search.placeholder')}
            aria-label={t('search.placeholder')}
          />
        </form>
      </div>

      <div className="app-header__right">
        {user?.level !== 'guest' && (
          <Link to="/capture" title={t('nav.capture')}>
            ➕
          </Link>
        )}

        <div className="user-menu">
          <button onClick={() => setMenuOpen((v) => !v)} aria-haspopup="menu" aria-expanded={menuOpen}>
            {user?.name}
          </button>
          {menuOpen && (
            <div className="user-menu__dropdown" role="menu">
              <Link to="/settings" role="menuitem" onClick={() => setMenuOpen(false)}>
                {t('userMenu.settings')}
              </Link>
              <button role="menuitem" onClick={() => void logout()}>
                {t('userMenu.logout')}
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}
