import { Outlet } from 'react-router-dom'
import { Header } from './Header'
import { Sidebar } from './Sidebar'
import { VersionBadge } from '../components/VersionBadge'

/**
 * Post-login shell (briefing 11.2). On mobile the sidebar is meant to
 * collapse into an expandable menu ("voraussichtlich in ein ausklappbares
 * Menü überführt") — TODO: add a hamburger toggle + CSS breakpoint once the
 * responsive design pass happens; the sidebar is always rendered for now.
 */
export function AppLayout() {
  return (
    <div className="app-shell">
      <Header />
      <div className="app-shell__body">
        <Sidebar />
        <main className="app-shell__content">
          <Outlet />
        </main>
      </div>
      <footer className="app-shell__footer">
        <VersionBadge />
      </footer>
    </div>
  )
}
