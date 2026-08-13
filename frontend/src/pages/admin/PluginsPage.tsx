import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface ConfigField {
  key: string
  /** 'password' is rendered as a masked input, for secrets like an API key. */
  type: 'text' | 'password'
  required: boolean
}

interface Plugin {
  id: number
  provider_key: string
  name: string
  media_type: 'book' | 'cd' | 'dvd_bluray'
  enabled: boolean
  priority: number
  config: Record<string, unknown> | null
  /** Declared by the matching backend provider class (GitHub issue #29) — not stored, computed per request. */
  config_fields: ConfigField[]
}

/**
 * A config field's key (e.g. "api_key") only travels as a stable identifier
 * from the backend (see MetadataProviderConfigField's docblock) — same
 * precedent as Library.media_type. The display label is resolved here via
 * i18n, falling back to a humanized version of the key itself so a new
 * provider's field still renders something reasonable before anyone adds a
 * translation for it.
 */
function fieldLabel(t: (key: string, opts?: Record<string, unknown>) => string, key: string): string {
  const translated = t(`admin.pluginConfig.fields.${key}`, { defaultValue: '' })
  if (translated) return translated
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

/**
 * Metadata source plugins (briefing 8.2/15.): enable/disable and reorder
 * (lower priority is tried first, see MetadataImportService). New
 * providers are registered backend-side in MetadataProviderRegistry —
 * this page only toggles/reorders/configures what's already registered, it
 * can't add new provider classes.
 *
 * Provider-specific settings (e.g. UpcMdbProvider's `api_key`, GitHub issue
 * #29) are edited in a real per-plugin settings dialog, one input per field
 * the provider declares via `configFields()` — rather than the raw JSON
 * textarea this used to be, which required knowing the exact shape of
 * `config` ahead of time.
 */
export function PluginsPage() {
  const { t } = useTranslation()
  const [plugins, setPlugins] = useState<Plugin[]>([])
  const [error, setError] = useState<string | null>(null)
  const [editingPluginId, setEditingPluginId] = useState<number | null>(null)
  const [formValues, setFormValues] = useState<Record<string, string>>({})
  const dialogRef = useRef<HTMLDialogElement>(null)

  async function load() {
    const { data } = await apiClient.get<Plugin[]>('/metadata/plugins')
    setPlugins(data)
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

  function openSettings(plugin: Plugin) {
    setEditingPluginId(plugin.id)
    setFormValues(Object.fromEntries(plugin.config_fields.map((f) => [f.key, String(plugin.config?.[f.key] ?? '')])))
    dialogRef.current?.showModal()
  }

  function closeSettings() {
    dialogRef.current?.close()
    setEditingPluginId(null)
  }

  async function saveSettings(e: React.FormEvent) {
    e.preventDefault()
    const plugin = plugins.find((p) => p.id === editingPluginId)
    if (!plugin) return
    // Omit blanked-out fields rather than storing empty strings — leaves the
    // field simply absent from `config`, same as it never having been set.
    const config = Object.fromEntries(Object.entries(formValues).filter(([, value]) => value !== ''))
    await update(plugin, { config })
    closeSettings()
  }

  const editingPlugin = plugins.find((p) => p.id === editingPluginId) ?? null

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
            <th>{t('admin.pluginConfig.settings')}</th>
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
                {p.config_fields.length === 0 ? (
                  <span className="plugin-config__none">{t('admin.pluginConfig.noConfig')}</span>
                ) : (
                  <button type="button" onClick={() => openSettings(p)}>
                    {t('admin.pluginConfig.settings')}
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <dialog ref={dialogRef} onClose={() => setEditingPluginId(null)} className="plugin-config-dialog">
        {editingPlugin && (
          <form onSubmit={(e) => void saveSettings(e)}>
            <h3>{editingPlugin.name}</h3>
            {editingPlugin.config_fields.map((field) => (
              <label key={field.key}>
                {fieldLabel(t, field.key)}
                <input
                  type={field.type}
                  required={field.required}
                  value={formValues[field.key] ?? ''}
                  onChange={(e) => setFormValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                />
              </label>
            ))}
            <div className="plugin-config-dialog__actions">
              <button type="submit">{t('admin.actions.save')}</button>
              <button type="button" onClick={closeSettings}>
                {t('admin.actions.cancel')}
              </button>
            </div>
          </form>
        )}
      </dialog>
    </section>
  )
}
