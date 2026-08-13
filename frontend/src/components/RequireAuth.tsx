import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Spinner } from './Spinner'

/** Gate for the entire post-login app (briefing 11.1: no function without valid login). */
export function RequireAuth() {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <Spinner fullPage />
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />

  return <Outlet />
}

/** Gate for admin-only routes (briefing 15.), e.g. wraps the /admin subtree. */
export function RequireAdmin() {
  const { user } = useAuth()

  if (user?.level !== 'admin') return <Navigate to="/" replace />

  return <Outlet />
}
