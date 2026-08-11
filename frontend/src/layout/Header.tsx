import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import { Logo } from '../components/Logo'

/**
 * Top header (briefing 11.2): logo + app name + search on the left;
 * statistics/capture/administration quick-access + user menu on the right.
 * The quick-access icons duplicate sidebar entries deliberately — the
 * briefing calls this out as intentional convenience, not a bug (11.2 note).
 */
export function Header() {
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
        <Link to="/statistics" title={t('nav.statistics')}>
          📊
        </Link>
        {user?.level !== 'guest' && (
          <Link to="/capture" title={t('nav.capture')}>
            ➕
          </Link>
        )}
        {user?.level === 'admin' && (
          <Link to="/admin" title={t('nav.administration')}>
            ⚙️
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
