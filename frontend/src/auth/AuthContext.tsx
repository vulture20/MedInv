import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { apiClient, fetchCsrfCookie } from '../api/client'

/** Mirrors backend/app/Models/User.php's fillable/visible fields. */
export interface User {
  id: number
  name: string
  email: string
  level: 'guest' | 'user' | 'admin'
  is_active: boolean
  preferred_language: string
  preferred_template: 'light' | 'dark'
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
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [mailServerHealthy, setMailServerHealthy] = useState(true)
  const [loading, setLoading] = useState(true)

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
  }, [])

  const logout = useCallback(async () => {
    await apiClient.post('/logout')
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ user, mailServerHealthy, loading, login, logout, refreshMailStatus }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider')
  return ctx
}
