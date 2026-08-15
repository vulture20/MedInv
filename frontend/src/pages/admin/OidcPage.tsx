import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface OidcSettings {
  enabled: boolean
  issuer: string | null
  client_id: string | null
  provider_name: string
  auto_provision: boolean
  default_level: 'guest' | 'user'
}

/**
 * OpenID Connect login configuration (briefing: none — GitHub issue #16,
 * beyond the original spec). Deliberate structural mirror of MailPage.tsx:
 * the stored client_secret is never sent back down
 * (AdminSettingsController::index() omits it, same policy as mail.password),
 * so the secret field here always starts empty and is only included in the
 * save request when the admin actually types a new one.
 *
 * `auto_provision`/`default_level` resolve the two open questions GitHub
 * issue #16 itself raised ("should a first-time OIDC login create a new
 * account, or only work for one an admin already created? what level
 * should it get?") as runtime settings rather than a hardcoded answer —
 * default_level is capped to guest/user here (matching
 * AdminSettingsController::updateOidc()'s validation) since auto-
 * provisioning an admin account is never allowed, regardless of what an
 * admin picks.
 */
export function OidcPage() {
  const { t } = useTranslation()
  const [settings, setSettings] = useState<OidcSettings | null>(null)
  const [clientSecret, setClientSecret] = useState('')
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function load() {
    const { data } = await apiClient.get<{ oidc: OidcSettings }>('/admin/settings')
    setSettings(data.oidc)
  }

  useEffect(() => {
    void load()
  }, [])

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (!settings) return
    setError(null)
    setSaved(false)
    try {
      const { data } = await apiClient.put<OidcSettings>('/admin/settings/oidc', {
        enabled: settings.enabled,
        issuer: settings.issuer,
        client_id: settings.client_id,
        provider_name: settings.provider_name,
        auto_provision: settings.auto_provision,
        default_level: settings.default_level,
        ...(clientSecret ? { client_secret: clientSecret } : {}),
      })
      setSettings(data)
      setClientSecret('')
      setSaved(true)
    } catch (err) {
      setError(describeError(err, t))
    }
  }

  if (!settings) return null

  return (
    <section>
      <h2>{t('admin.oidc')}</h2>
      <p className="hint">{t('admin.oidcSettings.intro')}</p>
      <form onSubmit={save}>
        <label>
          <input
            type="checkbox"
            checked={settings.enabled}
            onChange={(e) => setSettings({ ...settings, enabled: e.target.checked })}
          />
          {t('admin.oidcSettings.enabled')}
        </label>
        <label>
          {t('admin.oidcSettings.providerName')}
          <input
            value={settings.provider_name}
            onChange={(e) => setSettings({ ...settings, provider_name: e.target.value })}
            placeholder="Pocket ID"
            required
          />
        </label>
        <label>
          {t('admin.oidcSettings.issuer')}
          <input
            type="url"
            value={settings.issuer ?? ''}
            onChange={(e) => setSettings({ ...settings, issuer: e.target.value })}
            placeholder="https://id.example.com"
          />
        </label>
        <p className="hint">{t('admin.oidcSettings.issuerHint')}</p>
        <label>
          {t('admin.oidcSettings.clientId')}
          <input value={settings.client_id ?? ''} onChange={(e) => setSettings({ ...settings, client_id: e.target.value })} />
        </label>
        <label>
          {t('admin.oidcSettings.clientSecret')}
          <input type="password" value={clientSecret} onChange={(e) => setClientSecret(e.target.value)} />
        </label>
        <p className="hint">{t('admin.oidcSettings.clientSecretHint')}</p>
        <label>
          <input
            type="checkbox"
            checked={settings.auto_provision}
            onChange={(e) => setSettings({ ...settings, auto_provision: e.target.checked })}
          />
          {t('admin.oidcSettings.autoProvision')}
        </label>
        <p className="hint">{t('admin.oidcSettings.autoProvisionHint')}</p>
        <label>
          {t('admin.oidcSettings.defaultLevel')}
          <select
            value={settings.default_level}
            onChange={(e) => setSettings({ ...settings, default_level: e.target.value as OidcSettings['default_level'] })}
            disabled={!settings.auto_provision}
          >
            <option value="guest">guest</option>
            <option value="user">user</option>
          </select>
        </label>
        <p className="hint">{t('admin.oidcSettings.levelClaimHint')}</p>
        <button type="submit">{t('admin.actions.save')}</button>
        {saved && <p role="status">{t('admin.oidcSettings.saved')}</p>}
        {error && <p role="alert">{error}</p>}
      </form>
    </section>
  )
}
