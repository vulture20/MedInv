import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { apiClient, fetchCsrfCookie } from '../api/client'
import { onSessionEnded, type SessionEndReason } from '../api/authEvents'

/** Mirrors backend/app/Models/User.php's fillable/visible fields. */
export interface User {
  id: number
  name: string
  email: string
  level: 'guest' | 'user' | 'admin'
  is_active: boolean
  preferred_language: string
  // Not just 'light' | 'dark' — GitHub issue #11 lets this be any
  // registered runtime template's code too, same reasoning as
  // preferred_language above.
  preferred_template: string
}

interface AuthContextValue {
  user: User | null
  /** Surfaces the red admin warning + disables password reset per briefing 12.2. */
  mailServerHealthy: boolean
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /**
   * Re-fetches `mail_server_healthy` from `/me` without touching `loading`/`user`
   * flow. Call this after saving mail settings or sending a test mail
   * (AdminSettingsController::updateMail/testMail) so the app-wide indicator
   * (e.g. the "forgot password" gate on LoginPage) doesn't stay stale from
   * whenever this session last logged in or loaded.
   */
  refreshMailStatus: () => Promise<void>
  /**
   * Why the session just ended, if it was cut short mid-use rather than an
   * ordinary logout — set from apiClient's response interceptor via
   * authEvents.ts on a 401 (session expired) or a 403 `account_deactivated`
   * (briefing 4.1, GitHub issue #5). `null` otherwise, including the normal
   * "was never logged in" case. LoginPage reads this to show a specific
   * message and clears it via clearSessionEndReason() once shown.
   */
  sessionEndReason: SessionEndReason | null
  clearSessionEndReason: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [mailServerHealthy, setMailServerHealthy] = useState(true)
  const [loading, setLoading] = useState(true)
  const [sessionEndReason, setSessionEndReason] = useState<SessionEndReason | null>(null)

  const clearSessionEndReason = useCallback(() => setSessionEndReason(null), [])

  useEffect(() => {
    // Only surfaces the message when a *known* session was cut short — reads
    // the current user via the functional setUser form (rather than closing
    // over `user`) so this effect can register once on mount instead of
    // re-subscribing on every login/logout. An anonymous visitor's very
    // first (expected) 401 from the initial /me check below must NOT show
    // "your session expired": there never was one.
    return onSessionEnded((reason) => {
      setUser((current) => {
        if (current) setSessionEndReason(reason)

        return null
      })
    })
  }, [])

  const refresh = useCallback(async () => {
    try {
      const { data } = await apiClient.get<{ user: User; mail_server_healthy: boolean }>('/me')
      setUser(data.user)
      setMailServerHealthy(data.mail_server_healthy)
    } catch {
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const refreshMailStatus = useCallback(async () => {
    try {
      const { data } = await apiClient.get<{ mail_server_healthy: boolean }>('/me')
      setMailServerHealthy(data.mail_server_healthy)
    } catch {
      // Leave the current value in place — a failed refresh shouldn't flip
      // the indicator or affect the logged-in user/session state.
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    await fetchCsrfCookie()
    const { data } = await apiClient.post<{ user: User; mail_server_healthy: boolean }>('/login', {
      email,
      password,
    })
    setUser(data.user)
    setMailServerHealthy(data.mail_server_healthy)
    setSessionEndReason(null)
  }, [])

  const logout = useCallback(async () => {
    await apiClient.post('/logout')
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider
      value={{
        user,
        mailServerHealthy,
        loading,
        login,
        logout,
        refreshMailStatus,
        sessionEndReason,
        clearSessionEndReason,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider')
  return ctx
}
