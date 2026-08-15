import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { useAuth } from '../../auth/AuthContext'
import { Logo } from '../../components/Logo'
import { VersionBadge } from '../../components/VersionBadge'
import { apiClient, API_BASE } from '../../api/client'

/** error_code values AuthController::loginError() can return — kept in sync with backend/app/Http/Controllers/Api/AuthController.php. */
const KNOWN_ERROR_CODES = ['invalid_credentials', 'account_locked', 'account_deactivated'] as const

/**
 * error_code values OidcAuthController::failure() can redirect back here
 * with as `?oidc_error=<code>` — kept in sync with that method. Arrives via
 * a query param rather than an axios error response, since the whole OIDC
 * flow is a full-page browser redirect (GitHub issue #16), not an XHR call.
 */
const KNOWN_OIDC_ERROR_CODES = [
  'oidc_provider_error',
  'oidc_state_mismatch',
  'oidc_failed',
  'oidc_no_account',
  'oidc_account_deactivated',
] as const

/**
 * Login screen (briefing 11.1). Shows the red admin mail-server warning and
 * greys out "forgot password" whenever mailServerHealthy is false (12.2) —
 * note that flag only reflects reality *after* a successful login (the /me
 * check); a not-yet-authenticated visitor can't see it, matching the
 * briefing's "beim Einloggen" wording (the warning is admin-facing, shown
 * once they're in, not to anonymous visitors).
 */
export function LoginPage() {
  const { t } = useTranslation()
  const { user, mailServerHealthy, login, sessionEndReason } = useAuth()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [oidcConfig, setOidcConfig] = useState<{ enabled: boolean; provider_name: string } | null>(null)

  // Public probe (GitHub issue #16) — decides whether to render the SSO
  // button at all, and with what label, without requiring authentication.
  useEffect(() => {
    apiClient
      .get<{ enabled: boolean; provider_name: string }>('/auth/oidc/config')
      .then(({ data }) => setOidcConfig(data))
      .catch(() => setOidcConfig({ enabled: false, provider_name: '' }))
  }, [])

  // OidcAuthController::failure() redirects back here with `?oidc_error=<code>`
  // instead of an axios error response, since the whole flow is a full-page
  // browser redirect rather than an XHR call — same KNOWN_ERROR_CODES-style
  // translation, just sourced from the URL instead of a failed request.
  useEffect(() => {
    const oidcError = searchParams.get('oidc_error')
    if (!oidcError) return
    if ((KNOWN_OIDC_ERROR_CODES as readonly string[]).includes(oidcError)) {
      setError(t(`errors.${oidcError}`))
    } else {
      setError(t('errors.generic'))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (user) {
    const from = (location.state as { from?: Location })?.from?.pathname ?? '/'
    return <Navigate to={from} replace />
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await login(email, password)
    } catch (err) {
      // Backend returns a stable `error_code` (see AuthController::loginError())
      // rather than prose, precisely so this can go through i18n instead of
      // matching on English message text. Anything else (a 500 from a
      // misconfigured server, a network failure, ...) must NOT be relabeled
      // as "invalid credentials" — that hides the real problem from both the
      // user and whoever debugs it later. Fall back to a generic message
      // instead, and always log the real error for diagnosis.
      const code = isAxiosError(err) ? (err.response?.data?.error_code as string | undefined) : undefined
      if (!KNOWN_ERROR_CODES.includes(code as (typeof KNOWN_ERROR_CODES)[number])) {
        console.error('Login failed with an unexpected error:', err)
        setError(t('errors.generic'))
      } else {
        setError(t(`errors.${code}`))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login-screen">
      <form className="login-form" onSubmit={handleSubmit}>
        <div className="login-form__brand">
          <Logo size={48} />
        </div>
        <h1>{t('login.title')}</h1>

        {user && !mailServerHealthy && (
          <p className="warning warning--danger">{t('login.mailServerWarning')}</p>
        )}
        {/* Set by AuthContext when apiClient's interceptor sees a 401 (session
            expired) or a 403 account_deactivated (briefing 4.1) on some other
            request — see authEvents.ts. Cleared automatically on the next
            successful login. */}
        {!error && sessionEndReason && (
          <p className="warning warning--danger">
            {sessionEndReason === 'account_deactivated' ? t('errors.account_deactivated') : t('login.sessionExpired')}
          </p>
        )}
        {error && <p className="warning warning--danger">{error}</p>}

        <label>
          {t('login.email')}
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
        </label>
        <label>
          {t('login.password')}
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>

        <button type="submit" disabled={submitting}>
          {t('login.submit')}
        </button>

        {/* Additional to, not a replacement for, the password form above (GitHub issue #16) — only
            rendered once /auth/oidc/config confirms the admin has actually configured a provider. A
            plain <a>, not a client-side navigation: the whole point is leaving this SPA entirely to
            authenticate at the provider, then coming back once OidcAuthController::callback() redirects here. */}
        {oidcConfig?.enabled && (
          <a href={`${API_BASE}/api/auth/oidc/redirect`} className="login-form__sso">
            {t('login.ssoButton', { provider: oidcConfig.provider_name })}
          </a>
        )}

        <Link to="/password/forgot" aria-disabled={!mailServerHealthy} className="login-form__forgot">
          {t('login.forgotPassword')}
        </Link>

        <div className="login-form__footer">
          <VersionBadge />
        </div>
      </form>
    </div>
  )
}
