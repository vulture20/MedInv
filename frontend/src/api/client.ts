import axios, { isAxiosError } from 'axios'
import { notifySessionEnded } from './authEvents'

/**
 * Backend base URL. The Laravel API lives at API_BASE + '/api/*'; the
 * Sanctum CSRF cookie endpoint lives at API_BASE + '/sanctum/csrf-cookie'
 * (no /api prefix — see backend/routes: it's registered outside routes/api.php).
 */
export const API_BASE = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'

/**
 * Sanctum SPA auth (briefing 11.1 login flow): session cookie + CSRF token,
 * not bearer tokens. `withCredentials` is required for both the cookie
 * round-trip and the `supports_credentials` CORS setting on the backend
 * (backend/config/cors.php).
 */
export const apiClient = axios.create({
  baseURL: `${API_BASE}/api`,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

/** Must be called once before the first login attempt (sets the XSRF-TOKEN cookie). */
export async function fetchCsrfCookie(): Promise<void> {
  await axios.get(`${API_BASE}/sanctum/csrf-cookie`, { withCredentials: true })
}

/**
 * 401 (session expired — Sanctum rejected the cookie, e.g. it timed out or
 * was invalidated server-side) and 403 with error_code `account_deactivated`
 * (EnsureUserIsActive middleware — an admin deactivated this account
 * mid-session, briefing 4.1) both mean the current session is over and no
 * further request will succeed until a fresh login. Rather than a global
 * toast (there isn't one — see notifySessionEnded()'s docblock),
 * AuthContext reacts by clearing `user`, which RequireAuth already turns
 * into a redirect to /login on its own; LoginPage reads *why* from
 * useAuth().sessionEndReason to show a specific message instead of the
 * generic error every other failed request still falls back to.
 */
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (isAxiosError(error) && error.response) {
      const { status, data } = error.response
      if (status === 401) {
        notifySessionEnded('session_expired')
      } else if (status === 403 && (data as { error_code?: string })?.error_code === 'account_deactivated') {
        notifySessionEnded('account_deactivated')
      }
    }

    return Promise.reject(error)
  },
)
