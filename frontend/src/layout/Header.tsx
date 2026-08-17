import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Logo } from '../components/Logo'

interface HeaderProps {
  /** Whether the mobile off-canvas sidebar is currently expanded (briefing 11.2). */
  sidebarOpen: boolean
  /** Toggles the mobile sidebar; the button itself is hidden above the ≤768px breakpoint. */
  onToggleSidebar: () => void
}

/**
 * Top header (briefing 11.2): logo + app name + search — nothing else.
 * This used to also carry a capture quick-access icon and a user-name
 * dropdown (Benutzereinstellungen/Abmelden) on the right, both removed
 * since they fully duplicated entries the sidebar already has: "Erfassung"
 * in the main nav list, and the account group (name/Benutzereinstellungen/
 * Abmelden) appended at its bottom — see Sidebar.tsx. The statistics and
 * administration quick-access icons were removed earlier for the same
 * reason, so this header is now purely branding + search.
 */
export function Header({ sidebarOpen, onToggleSidebar }: HeaderProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [query, setQuery] = useState('')

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
    </header>
  )
}
