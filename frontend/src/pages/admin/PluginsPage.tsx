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
}

/**
 * Metadata source plugins (briefing 8.2/15.): enable/disable and reorder
 * (lower priority is tried first, see MetadataImportService). New
 * providers are registered backend-side in MetadataProviderRegistry —
 * this page only toggles/reorders what's already registered, it can't add
 * new provider classes.
 */
export function PluginsPage() {
  const { t } = useTranslation()
  const [plugins, setPlugins] = useState<Plugin[]>([])
  const [error, setError] = useState<string | null>(null)

  async function load() {
    const { data } = await apiClient.get<Plugin[]>('/metadata/plugins')
    setPlugins(data)
  }

  useEffect(() => {
    void load()
  }, [])

  async function update(plugin: Plugin, patch: Partial<Pick<Plugin, 'enabled' | 'priority'>>) {
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
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
