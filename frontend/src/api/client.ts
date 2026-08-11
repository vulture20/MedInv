import axios from 'axios'

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
 * TODO: a 401 here (session expired) should redirect to /login; a 403 with
 * `message: "Account is deactivated."` (briefing 4.1) should show a
 * dedicated notice instead of the generic error toast. Wire both up once
 * the app shell has a global toast/redirect mechanism.
 */
apiClient.interceptors.response.use(
  (response) => response,
  (error) => Promise.reject(error),
)
