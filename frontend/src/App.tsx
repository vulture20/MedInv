import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { ThemeProvider } from './theme/ThemeContext'
import { AppLayout } from './layout/AppLayout'
import { RequireAuth, RequireAdmin } from './components/RequireAuth'
import { LoginPage } from './pages/login/LoginPage'
import { DashboardPage } from './pages/dashboard/DashboardPage'
import { CapturePage } from './pages/capture/CapturePage'
import { LibrariesPage } from './pages/libraries/LibrariesPage'
import { LibraryDetailPage } from './pages/libraries/LibraryDetailPage'
import { StatisticsPage } from './pages/statistics/StatisticsPage'
import { SearchPage } from './pages/search/SearchPage'
import { SettingsPage } from './pages/settings/SettingsPage'
import { AdminPage } from './pages/admin/AdminPage'

/**
 * Route tree mirrors the sidebar/header structure in briefing 11.2.
 * RequireAuth enforces "no function without login" (11.1); RequireAdmin
 * additionally gates /admin (15.). Per-library read/write visibility is
 * NOT re-implemented here — it's enforced backend-side (LibraryAccessService)
 * and pages simply render whatever the API returns.
 */
function App() {
  return (
    <AuthProvider>
      <ThemeProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />

            <Route element={<RequireAuth />}>
              <Route element={<AppLayout />}>
                <Route index element={<DashboardPage />} />
                <Route path="capture" element={<CapturePage />} />
                <Route path="libraries" element={<LibrariesPage />} />
                <Route path="libraries/:id" element={<LibraryDetailPage />} />
                <Route path="statistics" element={<StatisticsPage />} />
                <Route path="search" element={<SearchPage />} />
                <Route path="settings" element={<SettingsPage />} />
                <Route element={<RequireAdmin />}>
                  <Route path="admin" element={<AdminPage />} />
                </Route>
              </Route>
            </Route>
          </Routes>
        </BrowserRouter>
      </ThemeProvider>
    </AuthProvider>
  )
}

export default App
