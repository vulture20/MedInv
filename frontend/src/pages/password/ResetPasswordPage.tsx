import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient, fetchCsrfCookie } from '../../api/client'
import { Logo } from '../../components/Logo'

const KNOWN_ERROR_CODES = ['invalid_token', 'invalid_user', 'throttled', 'reset_failed', 'mail_unavailable'] as const

/**
 * Second step of the self-service password reset (briefing 12.3): the link
 * from the invitation/reset email (see AppServiceProvider::
 * applyPasswordResetUrl()) lands here with `token`/`email` query params,
 * which are submitted alongside the new password to
 * PasswordResetController::reset().
 */
export function ResetPasswordPage() {
  const { t } = useTranslation()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await fetchCsrfCookie()
      await apiClient.post('/password/reset', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      setDone(true)
    } catch (err) {
      setError(describeError(err, t))
    } finally {
      setSubmitting(false)
    }
  }

  if (!token || !email) {
    return (
      <div className="login-screen">
        <div className="login-form">
          <div className="login-form__brand">
            <Logo size={48} />
          </div>
          <p className="warning warning--danger">{t('passwordReset.errors.invalid_token')}</p>
          <div className="login-form__footer">
            <Link to="/password/forgot">{t('passwordReset.forgotTitle')}</Link>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="login-screen">
      <div className="login-form">
        <div className="login-form__brand">
          <Logo size={48} />
        </div>
        <h1>{t('passwordReset.resetTitle')}</h1>

        {done ? (
          <p role="status">{t('passwordReset.resetSuccess')}</p>
        ) : (
          <form onSubmit={handleSubmit}>
            {error && <p className="warning warning--danger">{error}</p>}
            <label>
              {t('passwordReset.newPassword')}
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoFocus
              />
            </label>
            {/* Always visible, not just on error — same policy MedInvPasswordPolicy
                enforces server-side for every password field in the app. */}
            <p>{t('admin.passwordHint')}</p>
            <label>
              {t('passwordReset.confirmPassword')}
              <input
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                required
              />
            </label>
            <button type="submit" disabled={submitting}>
              {t('passwordReset.resetSubmit')}
            </button>
          </form>
        )}

        <div className="login-form__footer">
          <Link to="/login">{t('passwordReset.backToLogin')}</Link>
        </div>
      </div>
    </div>
  )
}

function describeError(err: unknown, t: ReturnType<typeof useTranslation>['t']): string {
  if (!isAxiosError(err)) return t('passwordReset.errors.generic')

  const data = err.response?.data as { error_code?: string; errors?: Record<string, string[]> } | undefined

  if (data?.error_code && (KNOWN_ERROR_CODES as readonly string[]).includes(data.error_code)) {
    return t(`passwordReset.errors.${data.error_code}`)
  }
  // Laravel's own validation shape (e.g. `confirmed` mismatch, MedInvPasswordPolicy) —
  // the password-policy hint above already explains the rules, so just surface it.
  if (data?.errors?.password) return t('admin.errors.passwordPolicy')

  return t('passwordReset.errors.generic')
}
