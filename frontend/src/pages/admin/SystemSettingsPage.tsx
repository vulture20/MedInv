import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface SecuritySettings {
  throttle_max_attempts: number
  throttle_window_minutes: number
  throttle_lock_minutes: number
}

type LogLevel = 'DEBUG' | 'INFO' | 'WARNING' | 'ERROR'

/**
 * The remaining runtime settings that don't have a page of their own:
 * brute-force throttling (briefing 12.4, enforced by BruteForceProtection)
 * and the log level. Mail lives on its own page, backup schedule/retention
 * lives with the backups list — see pages/admin/{Mail,Backups}Page.tsx.
 */
export function SystemSettingsPage() {
  const { t } = useTranslation()
  const [security, setSecurity] = useState<SecuritySettings | null>(null)
  const [securitySaved, setSecuritySaved] = useState(false)
  const [securityError, setSecurityError] = useState<string | null>(null)
  const [loglevel, setLoglevel] = useState<LogLevel | null>(null)
  const [loglevelSaved, setLoglevelSaved] = useState(false)
  const [loglevelError, setLoglevelError] = useState<string | null>(null)

  async function load() {
    const { data } = await apiClient.get<{ security: SecuritySettings; loglevel: LogLevel }>('/admin/settings')
    setSecurity(data.security)
    setLoglevel(data.loglevel)
  }

  useEffect(() => {
    void load()
  }, [])

  async function saveSecurity(e: React.FormEvent) {
    e.preventDefault()
    if (!security) return
    setSecurityError(null)
    setSecuritySaved(false)
    try {
      const { data } = await apiClient.put<SecuritySettings>('/admin/settings/security', security)
      setSecurity(data)
      setSecuritySaved(true)
    } catch (err) {
      setSecurityError(describeError(err, t))
    }
  }

  async function saveLoglevel(e: React.FormEvent) {
    e.preventDefault()
    if (!loglevel) return
    setLoglevelError(null)
    setLoglevelSaved(false)
    try {
      const { data } = await apiClient.put<{ loglevel: LogLevel }>('/admin/settings/loglevel', { loglevel })
      setLoglevel(data.loglevel)
      setLoglevelSaved(true)
    } catch (err) {
      setLoglevelError(describeError(err, t))
    }
  }

  return (
    <>
      {security && (
        <section>
          <h2>{t('admin.securitySettings.title')}</h2>
          <form onSubmit={saveSecurity}>
            <label>
              {t('admin.securitySettings.maxAttempts')}
              <input
                type="number"
                min={1}
                value={security.throttle_max_attempts}
                onChange={(e) => setSecurity({ ...security, throttle_max_attempts: Number(e.target.value) })}
                required
              />
            </label>
            <label>
              {t('admin.securitySettings.windowMinutes')}
              <input
                type="number"
                min={1}
                value={security.throttle_window_minutes}
                onChange={(e) => setSecurity({ ...security, throttle_window_minutes: Number(e.target.value) })}
                required
              />
            </label>
            <label>
              {t('admin.securitySettings.lockMinutes')}
              <input
                type="number"
                min={1}
                value={security.throttle_lock_minutes}
                onChange={(e) => setSecurity({ ...security, throttle_lock_minutes: Number(e.target.value) })}
                required
              />
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {securitySaved && <p role="status">{t('admin.securitySettings.saved')}</p>}
            {securityError && <p role="alert">{securityError}</p>}
          </form>
        </section>
      )}

      {loglevel && (
        <section>
          <h2>{t('admin.logLevel.title')}</h2>
          <form onSubmit={saveLoglevel}>
            <label>
              {t('admin.logLevel.title')}
              <select value={loglevel} onChange={(e) => setLoglevel(e.target.value as LogLevel)}>
                <option value="DEBUG">DEBUG</option>
                <option value="INFO">INFO</option>
                <option value="WARNING">WARNING</option>
                <option value="ERROR">ERROR</option>
              </select>
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {loglevelSaved && <p role="status">{t('admin.logLevel.saved')}</p>}
            {loglevelError && <p role="alert">{loglevelError}</p>}
          </form>
        </section>
      )}
    </>
  )
}
