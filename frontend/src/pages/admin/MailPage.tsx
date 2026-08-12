import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { useAuth } from '../../auth/AuthContext'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface MailSettings {
  host: string
  port: number
  username: string | null
  encryption: 'ssl_tls' | 'starttls' | 'none'
  from_address: string
  from_name: string
  healthy: boolean
}

/**
 * Outgoing mail server config (briefing 12.2), applied live via
 * AppServiceProvider::boot() rather than requiring a restart. The stored
 * password is never sent back down (AdminSettingsController::index() omits
 * it), so the password field here starts empty and is only included in the
 * save request when the admin actually types a new one — otherwise it's
 * left untouched server-side.
 */
export function MailPage() {
  const { t } = useTranslation()
  const { user, refreshMailStatus } = useAuth()
  const [settings, setSettings] = useState<MailSettings | null>(null)
  const [password, setPassword] = useState('')
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [testTo, setTestTo] = useState('')
  const [testSending, setTestSending] = useState(false)
  const [testResult, setTestResult] = useState<string | null>(null)
  const [testError, setTestError] = useState<string | null>(null)

  async function load() {
    const { data } = await apiClient.get<{ mail: MailSettings }>('/admin/settings')
    setSettings(data.mail)
  }

  useEffect(() => {
    void load()
  }, [])

  useEffect(() => {
    if (user?.email) setTestTo((current) => current || user.email)
  }, [user])

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (!settings) return
    setError(null)
    setSaved(false)
    try {
      const { data } = await apiClient.put<{ healthy: boolean }>('/admin/settings/mail', {
        host: settings.host,
        port: settings.port,
        username: settings.username,
        encryption: settings.encryption,
        from_address: settings.from_address,
        from_name: settings.from_name,
        ...(password ? { password } : {}),
      })
      setSettings({ ...settings, healthy: data.healthy })
      setPassword('')
      setSaved(true)
      void refreshMailStatus()
    } catch (err) {
      setError(describeError(err, t))
    }
  }

  /**
   * Sends a real message through the *saved* config (AdminSettingsController::testMail) —
   * a separate action from `healthy` above, which only proves the SMTP server accepts a
   * connection, not that auth/from-address/relay rules actually deliver mail.
   */
  async function sendTestMail(e: React.FormEvent) {
    e.preventDefault()
    setTestResult(null)
    setTestError(null)
    setTestSending(true)
    try {
      await apiClient.post('/admin/settings/mail/test', { to: testTo })
      setTestResult(t('admin.mailSettings.testMailSent'))
      void refreshMailStatus()
    } catch (err) {
      if (isAxiosError(err)) {
        const data = err.response?.data as { error_code?: string; message?: string } | undefined
        if (data?.error_code === 'not_configured') {
          setTestError(t('admin.mailSettings.testMailNotConfigured'))
        } else if (data?.error_code === 'mail_test_failed') {
          setTestError(t('admin.mailSettings.testMailFailed', { message: data.message }))
        } else {
          setTestError(describeError(err, t))
        }
      } else {
        setTestError(describeError(err, t))
      }
    } finally {
      setTestSending(false)
    }
  }

  if (!settings) return null

  return (
    <section>
      <h2>{t('admin.mail')}</h2>
      <p>{settings.healthy ? t('admin.mailSettings.healthy') : t('admin.mailSettings.unhealthy')}</p>
      <form onSubmit={save}>
        <label>
          {t('admin.mailSettings.host')}
          <input value={settings.host} onChange={(e) => setSettings({ ...settings, host: e.target.value })} required />
        </label>
        <label>
          {t('admin.mailSettings.port')}
          <input
            type="number"
            value={settings.port}
            onChange={(e) => setSettings({ ...settings, port: Number(e.target.value) })}
            required
          />
        </label>
        <label>
          {t('admin.mailSettings.username')}
          <input
            value={settings.username ?? ''}
            onChange={(e) => setSettings({ ...settings, username: e.target.value })}
          />
        </label>
        <label>
          {t('admin.mailSettings.password')}
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <p>{t('admin.mailSettings.passwordHint')}</p>
        <label>
          {t('admin.mailSettings.encryption')}
          <select
            value={settings.encryption}
            onChange={(e) => setSettings({ ...settings, encryption: e.target.value as MailSettings['encryption'] })}
          >
            <option value="starttls">{t('admin.mailSettings.encryptionOptions.starttls')}</option>
            <option value="ssl_tls">{t('admin.mailSettings.encryptionOptions.ssl_tls')}</option>
            <option value="none">{t('admin.mailSettings.encryptionOptions.none')}</option>
          </select>
        </label>
        <label>
          {t('admin.mailSettings.fromAddress')}
          <input
            type="email"
            value={settings.from_address}
            onChange={(e) => setSettings({ ...settings, from_address: e.target.value })}
            required
          />
        </label>
        <label>
          {t('admin.mailSettings.fromName')}
          <input
            value={settings.from_name}
            onChange={(e) => setSettings({ ...settings, from_name: e.target.value })}
            required
          />
        </label>
        <button type="submit">{t('admin.actions.save')}</button>
        {saved && <p role="status">{t('admin.mailSettings.saved')}</p>}
        {error && <p role="alert">{error}</p>}
      </form>

      <h3>{t('admin.mailSettings.testMail')}</h3>
      <form onSubmit={sendTestMail}>
        <label>
          {t('admin.mailSettings.testMailTo')}
          <input type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} required />
        </label>
        <button type="submit" disabled={testSending}>
          {t('admin.mailSettings.testMailSend')}
        </button>
        {testResult && <p role="status">{testResult}</p>}
        {testError && <p role="alert">{testError}</p>}
      </form>
    </section>
  )
}
