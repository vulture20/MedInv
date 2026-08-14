import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
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

/** Every media type a plugin can belong to (Library.media_type's own union) — fixed order the grouped tables render in below. */
const MEDIA_TYPES: Plugin['media_type'][] = ['book', 'cd', 'dvd_bluray']

/**
 * Module-level, not inline object literals passed straight to useSensor()
 * — dnd-kit's useSensor(sensor, options) is
 * `useMemo(() => ({sensor, options}), [sensor, options])`, so a fresh
 * `{ activationConstraint: {...} }`/`{ coordinateGetter: ... }` literal on
 * every render (this component re-renders on every completed drag, since
 * it calls setPlugins) gave that memo a new `options` reference every
 * render, cascading into useSensors() also producing a brand-new sensors
 * array each time — unnecessary churn dnd-kit's own docs call out as
 * worth avoiding via a stable reference, exactly like this. Investigated
 * as a candidate explanation for GitHub issue #41's "works once, then
 * stuck" report; live testing (Playwright) showed this alone wasn't the
 * actual cause — see pluginsInGroup()'s docblock for what was — but it's
 * still a real, worthwhile fix to keep on its own merits.
 */
const POINTER_SENSOR_OPTIONS = { activationConstraint: { distance: 4 } }
const KEYBOARD_SENSOR_OPTIONS = { coordinateGetter: sortableKeyboardCoordinates }

/**
 * One media type's plugins in the order they're actually displayed —
 * sorted by priority, not left in whatever order the `plugins` array
 * itself happens to be in. Shared by both the render below (`groupPlugins`)
 * and handleDragEnd() so the two can never diverge from each other again:
 * this was originally only fixed at the render call site (GitHub issue
 * #41), but handleDragEnd() had its own independent, *unfixed* copy of the
 * exact same `.filter()`-with-no-sort derivation for computing drag
 * source/target indices. Right after a page load, array order and
 * priority order coincide (the backend already returns priority-sorted
 * rows), masking the bug for exactly one drag — but the very first
 * completed drag only ever changes `priority` values, never the array's
 * element order, so every drag after that computed source/target indices
 * against a list that no longer matched what was actually on screen,
 * silently producing a no-op reassignment. Confirmed live via Playwright
 * with realistic, slow pointer motion: drag 1 always worked, every drag
 * after it did visibly nothing.
 */
function pluginsInGroup(plugins: Plugin[], mediaType: Plugin['media_type']): Plugin[] {
  return plugins.filter((p) => p.media_type === mediaType).sort((a, b) => a.priority - b.priority)
}

/**
 * One draggable row (dnd-kit's useSortable) — only the handle cell carries
 * the drag listeners, not the whole row, so clicking the checkbox/settings
 * button never gets mistaken for the start of a drag.
 */
function SortableRow({ id, children }: { id: number; children: (handle: { attributes: object; listeners: object | undefined }) => React.ReactNode }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id })
  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : undefined,
  }

  return (
    <tr ref={setNodeRef} style={style}>
      {children({ attributes, listeners })}
    </tr>
  )
}

/**
 * Metadata source plugins (briefing 8.2/15.): enable/disable and reorder
 * (lower priority is tried first, see MetadataImportService). New
 * providers are registered backend-side in MetadataProviderRegistry —
 * this page only toggles/reorders/configures what's already registered, it
 * can't add new provider classes.
 *
 * Grouped by media type into one table each, rather than a single flat
 * table with a media-type column: `priority` only ever ranks providers
 * *within* one media type — MetadataProviderRegistry::enabledProvidersFor()
 * filters by media_type before ordering by priority, so two providers for
 * different media types never actually compete with each other. A flat,
 * mixed-media-type table made that ranking look global when it never was.
 *
 * Priority is set by dragging rows into the desired order (dnd-kit) rather
 * than typing a raw number — each group has its own independent
 * DndContext, so a drag can never cross into a different media type's
 * table to begin with; dropping renumbers just that group densely as
 * 0..N-1 and PUTs only the rows whose priority actually changed. dnd-kit's
 * keyboard sensor (Space to pick up, arrow keys to move, Space to drop)
 * covers the reordering a plain HTML5 drag-and-drop implementation
 * wouldn't have gotten for free.
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

  // Both options objects are module-level constants, not created inline
  // here — see POINTER_SENSOR_OPTIONS's docblock for why that stability
  // actually matters, not just style. distance: 4 (via
  // POINTER_SENSOR_OPTIONS) is a small activation distance so a plain
  // click on the handle (or a slightly imprecise click near it) doesn't
  // get mistaken for a drag.
  const sensors = useSensors(useSensor(PointerSensor, POINTER_SENSOR_OPTIONS), useSensor(KeyboardSensor, KEYBOARD_SENSOR_OPTIONS))

  async function load() {
    const { data } = await apiClient.get<Plugin[]>('/admin/metadata/plugins')
    setPlugins(data)
  }

  useEffect(() => {
    void load()
  }, [])

  async function update(plugin: Plugin, patch: Partial<Pick<Plugin, 'enabled' | 'priority' | 'config'>>) {
    setError(null)
    // Optimistic update so a checkbox click feels immediate.
    setPlugins((prev) => prev.map((p) => (p.id === plugin.id ? { ...p, ...patch } : p)))
    try {
      await apiClient.put(`/admin/metadata/plugins/${plugin.id}`, patch)
    } catch (err) {
      setError(describeError(err, t))
      await load()
    }
  }

  /**
   * Renumbers the dropped-into group densely (0..N-1) from the new row
   * order and persists only the rows whose priority actually moved — for
   * a group of 2-3 providers that's at most 2-3 PUTs, the same endpoint
   * the old priority number input already used
   * (PUT /admin/metadata/plugins/{id}, unchanged on the backend).
   */
  async function handleDragEnd(mediaType: Plugin['media_type'], event: DragEndEvent) {
    const { active, over } = event
    if (!over || active.id === over.id) return

    // pluginsInGroup(), not a fresh .filter() here — active/over's indices
    // must be computed against the same priority-sorted order the rows are
    // actually rendered in (see that function's docblock for the bug this
    // caused when the two derivations disagreed).
    const groupIds = pluginsInGroup(plugins, mediaType).map((p) => p.id)
    const oldIndex = groupIds.indexOf(Number(active.id))
    const newIndex = groupIds.indexOf(Number(over.id))
    if (oldIndex === -1 || newIndex === -1) return

    const reorderedIds = arrayMove(groupIds, oldIndex, newIndex)
    const newPriorityById = new Map(reorderedIds.map((id, index) => [id, index]))
    const changed = plugins.filter((p) => newPriorityById.get(p.id) !== undefined && newPriorityById.get(p.id) !== p.priority)

    setPlugins((prev) => prev.map((p) => (newPriorityById.has(p.id) ? { ...p, priority: newPriorityById.get(p.id)! } : p)))

    setError(null)
    try {
      await Promise.all(changed.map((p) => apiClient.put(`/admin/metadata/plugins/${p.id}`, { priority: newPriorityById.get(p.id) })))
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
      {MEDIA_TYPES.map((mediaType) => {
        const groupPlugins = pluginsInGroup(plugins, mediaType)
        if (groupPlugins.length === 0) return null

        return (
          <div key={mediaType} className="plugin-group">
            <h3>{t(`libraries.mediaType.${mediaType}`)}</h3>
            {/*
              GitHub issue #41 follow-up: DndContext must wrap the whole
              <table>, not sit *inside* it next to <thead> — DndContext
              renders its own DOM (hidden a11y description/live-region
              <div>s) alongside whatever children it's given, and a <div>
              landing as a direct child of <table> (a sibling of <thead>/
              <tbody>, not inside either) is invalid HTML. React doesn't go
              through the HTML parser for DOM writes, so the browser never
              "fixes" that nesting the way it would for parsed markup — the
              invalid structure was really there, confirmed live via
              Playwright (element.outerHTML showed both DndDescribedBy-0
              and DndLiveRegion-0 as direct <table> children, right after
              </tbody>) — plausible cause of the reported "works once, then
              rows stop moving" behavior, since it left the actual DOM
              structure subtly different from a normal table every render
              after the first. SortableContext itself renders no DOM at
              all (a bare context provider), so it can still wrap just
              <tbody> without the same problem.
            */}
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={(event) => void handleDragEnd(mediaType, event)}>
              <table>
                <thead>
                  <tr>
                    <th aria-hidden="true" />
                    <th>{t('common.name')}</th>
                    <th>{t('admin.table.priority')}</th>
                    <th>{t('admin.table.enabled')}</th>
                    <th>{t('admin.pluginConfig.settings')}</th>
                  </tr>
                </thead>
                <SortableContext items={groupPlugins.map((p) => p.id)} strategy={verticalListSortingStrategy}>
                  <tbody>
                    {groupPlugins.map((p, index) => (
                      <SortableRow key={p.id} id={p.id}>
                        {({ attributes, listeners }) => (
                          <>
                            <td
                              className="plugin-drag-handle"
                              aria-label={t('admin.pluginOrdering.dragHandle', { name: p.name })}
                              {...attributes}
                              {...listeners}
                            >
                              ⠿
                            </td>
                            <td>{p.name}</td>
                            <td>{index + 1}</td>
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
                          </>
                        )}
                      </SortableRow>
                    ))}
                  </tbody>
                </SortableContext>
              </table>
            </DndContext>
          </div>
        )
      })}

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
