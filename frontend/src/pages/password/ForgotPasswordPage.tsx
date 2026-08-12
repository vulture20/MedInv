import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient, fetchCsrfCookie } from '../../api/client'
import { Logo } from '../../components/Logo'

/**
 * First step of the self-service password reset (briefing 12.3): request a
 * reset link by email. Reached from LoginPage's "forgot password" link,
 * itself greyed out whenever mailServerHealthy is false — this page
 * re-checks server-side too (PasswordResetController::ensureMailHealthy()),
 * since a not-yet-authenticated visitor could still navigate here directly.
 */
export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await fetchCsrfCookie()
      await apiClient.post('/password/email', { email })
      // Ignoring the backend's actual response body on purpose — it's a
      // deliberately generic English sentence (see PasswordResetController::
      // sendResetLink()'s docblock, to not allow enumerating accounts) that
      // this translated string already covers.
      setSent(true)
    } catch (err) {
      const code = isAxiosError(err) ? (err.response?.data?.error_code as string | undefined) : undefined
      setError(code === 'mail_unavailable' ? t('passwordReset.errors.mail_unavailable') : t('passwordReset.errors.generic'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login-screen">
      <div className="login-form">
        <div className="login-form__brand">
          <Logo size={48} />
        </div>
        <h1>{t('passwordReset.forgotTitle')}</h1>

        {sent ? (
          <p role="status">{t('passwordReset.linkSent')}</p>
        ) : (
          <form onSubmit={handleSubmit}>
            <p>{t('passwordReset.forgotIntro')}</p>
            {error && <p className="warning warning--danger">{error}</p>}
            <label>
              {t('login.email')}
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
            </label>
            <button type="submit" disabled={submitting}>
              {t('passwordReset.sendLink')}
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
