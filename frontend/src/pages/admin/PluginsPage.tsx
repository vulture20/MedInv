import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface Plugin {
  id: number
  provider_key: string
  name: string
  media_type: 'book' | 'cd' | 'dvd_bluray'
  enabled: boolean
  priority: number
  config: Record<string, unknown> | null
}

/**
 * Metadata source plugins (briefing 8.2/15.): enable/disable and reorder
 * (lower priority is tried first, see MetadataImportService). New
 * providers are registered backend-side in MetadataProviderRegistry —
 * this page only toggles/reorders what's already registered, it can't add
 * new provider classes.
 *
 * `config` (e.g. UpcMdbProvider's required `api_key`) is edited here as raw
 * JSON rather than a per-provider form — the column is a deliberately
 * generic per-provider settings bag (see MetadataPlugin's docblock), and a
 * bespoke field-by-field UI would need to know each provider's config
 * shape ahead of time, which the admin API itself doesn't.
 */
export function PluginsPage() {
  const { t } = useTranslation()
  const [plugins, setPlugins] = useState<Plugin[]>([])
  const [error, setError] = useState<string | null>(null)
  const [configDrafts, setConfigDrafts] = useState<Record<number, string>>({})
  const [configErrors, setConfigErrors] = useState<Record<number, string | null>>({})

  async function load() {
    const { data } = await apiClient.get<Plugin[]>('/metadata/plugins')
    setPlugins(data)
    setConfigDrafts(Object.fromEntries(data.map((p) => [p.id, JSON.stringify(p.config ?? {}, null, 2)])))
  }

  useEffect(() => {
    void load()
  }, [])

  async function update(plugin: Plugin, patch: Partial<Pick<Plugin, 'enabled' | 'priority' | 'config'>>) {
    setError(null)
    // Optimistic update so a checkbox click / number edit feels immediate.
    setPlugins((prev) => prev.map((p) => (p.id === plugin.id ? { ...p, ...patch } : p)))
    try {
      await apiClient.put(`/admin/metadata/plugins/${plugin.id}`, patch)
    } catch (err) {
      setError(describeError(err, t))
      await load()
    }
  }

  function saveConfig(plugin: Plugin) {
    const draft = configDrafts[plugin.id] ?? ''
    try {
      const config = JSON.parse(draft) as Record<string, unknown>
      setConfigErrors((prev) => ({ ...prev, [plugin.id]: null }))
      void update(plugin, { config })
    } catch {
      setConfigErrors((prev) => ({ ...prev, [plugin.id]: t('admin.pluginConfig.invalidJson') }))
    }
  }

  return (
    <section>
      <h2>{t('admin.plugins')}</h2>
      {error && <p role="alert">{error}</p>}
      <table>
        <thead>
          <tr>
            <th>{t('common.name')}</th>
            <th>{t('admin.table.mediaType')}</th>
            <th>{t('admin.table.priority')}</th>
            <th>{t('admin.table.enabled')}</th>
            <th>{t('admin.pluginConfig.label')}</th>
          </tr>
        </thead>
        <tbody>
          {plugins.map((p) => (
            <tr key={p.id}>
              <td>{p.name}</td>
              <td>{t(`libraries.mediaType.${p.media_type}`)}</td>
              <td>
                <input
                  type="number"
                  value={p.priority}
                  onChange={(e) => setPlugins((prev) => prev.map((x) => (x.id === p.id ? { ...x, priority: Number(e.target.value) } : x)))}
                  onBlur={(e) => void update(p, { priority: Number(e.target.value) })}
                />
              </td>
              <td>
                <input type="checkbox" checked={p.enabled} onChange={(e) => void update(p, { enabled: e.target.checked })} />
              </td>
              <td>
                <textarea
                  rows={4}
                  cols={30}
                  value={configDrafts[p.id] ?? ''}
                  onChange={(e) => setConfigDrafts((prev) => ({ ...prev, [p.id]: e.target.value }))}
                />
                <br />
                <button type="button" onClick={() => saveConfig(p)}>
                  {t('admin.actions.save')}
                </button>
                {configErrors[p.id] && <p role="alert">{configErrors[p.id]}</p>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
