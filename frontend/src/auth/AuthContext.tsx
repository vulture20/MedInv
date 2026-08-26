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
  // GitHub issue #194 — MediaItemController::index() uses this as the
  // default page size (App\Models\User::ITEMS_PER_PAGE_OPTIONS) whenever a
  // request doesn't send its own `per_page`, which LibraryDetailPage.tsx
  // never does — so setting this alone is enough to change page size
  // there, no request-building change needed.
  items_per_page: number
  // Set for an SSO-provisioned account (OidcAuthController::findOrCreateUser())
  // — such an account has no local password its owner could ever know, so
  // SettingsPage.tsx's password-change section is hidden whenever this is
  // non-null (GitHub issue #174).
  oidc_subject: string | null
}

interface AuthContextValue {
  user: User | null
  /** Surfaces the red admin warning + disables password reset per briefing 12.2. */
  mailServerHealthy: boolean
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /**
   * Permanently deletes the logged-in user's own account (GitHub issue #86,
   * `DELETE /me`) and clears the local session state the same way logout()
   * does. Deliberately doesn't also call `POST /logout` afterward — the
   * backend already invalidates the session as part of the deletion itself
   * (AccountSettingsController::destroy()), so there'd be nothing left to
   * log out of by the time this resolves.
   */
  deleteAccount: () => Promise<void>
  /**
   * GitHub issue #194's own follow-up bug report: SettingsPage.tsx's
   * `items_per_page` select initialized its local state from
   * `user.items_per_page` once, at mount — but nothing ever wrote a saved
   * value back into this context's own `user`, so navigating away and back
   * (an ordinary React remount, not a full page reload) re-read the exact
   * same stale snapshot `/me` returned at login/last load, silently
   * reverting the displayed selection to whatever it was back then (50, for
   * any account that hadn't changed it before this fix). preferred_template/
   * preferred_language never had this problem because they each already
   * read from a different, always-current live source instead of `user`
   * directly (ThemeContext's own `template` state, `i18n.language`) — see
   * SettingsPage.tsx's own docblock for why. `items_per_page` has no
   * equivalent global store of its own (nothing outside this one page reads
   * it at render time), so the fix here is the other, more general
   * direction: let a caller patch the *shared* `user` object itself right
   * after a successful save, so anything reading `user.<field>` afterward —
   * now or from a future remount — sees the real value instead of only ever
   * updating a component-local copy of it.
   */
  updateUser: (patch: Partial<User>) => void
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

  const deleteAccount = useCallback(async () => {
    await apiClient.delete('/me')
    setUser(null)
  }, [])

  /** See this function's own doc on AuthContextValue above. A no-op while logged out (current is null) — there's no user object to patch. */
  const updateUser = useCallback((patch: Partial<User>) => {
    setUser((current) => (current ? { ...current, ...patch } : current))
  }, [])

  return (
    <AuthContext.Provider
      value={{
        user,
        mailServerHealthy,
        loading,
        login,
        logout,
        deleteAccount,
        updateUser,
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
