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
    <AuthContext.Provider value={{ user, mailServerHealthy, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider')
  return ctx
}
