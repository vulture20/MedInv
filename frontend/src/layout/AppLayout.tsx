import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Header } from './Header'
import { Sidebar } from './Sidebar'
import { VersionBadge } from '../components/VersionBadge'

/**
 * Post-login shell (briefing 11.2). On mobile the sidebar collapses into an
 * expandable menu ("voraussichtlich in ein ausklappbares Menü überführt"):
 * the hamburger toggle lives in the header, the open/closed state lives here
 * since both Header (toggle button) and Sidebar (the panel itself) need it,
 * and the actual collapse-to-off-canvas behavior is a CSS breakpoint
 * (index.css's .sidebar / .sidebar--open, ≤768px) — above that width the
 * toggle is hidden and the sidebar renders inline as before.
 */
export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const location = useLocation()

  // Close the mobile menu on every navigation rather than leaving it open
  // over the newly loaded page.
  useEffect(() => {
    setSidebarOpen(false)
  }, [location.pathname])

  return (
    <div className="app-shell">
      <Header sidebarOpen={sidebarOpen} onToggleSidebar={() => setSidebarOpen((v) => !v)} />
      <div className="app-shell__body">
        <Sidebar open={sidebarOpen} />
        {sidebarOpen && <div className="sidebar-backdrop" onClick={() => setSidebarOpen(false)} />}
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
