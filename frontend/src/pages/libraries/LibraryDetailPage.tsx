import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { SortableHeader } from '../../components/SortableHeader'
import { describeError } from '../admin/adminErrors'
import { MediaItemDetailDialog, type MediaItem } from './MediaItemDetailDialog'
import { CreateMediaItemDialog } from './CreateMediaItemDialog'
import { LibrarySettingsDialog } from './LibrarySettingsDialog'
import { FIELD_SPECS, dateOnly, formatDuration, payloadFromValues, subtitleField } from './mediaItemFields'

/** One row of App\Models\LibraryShare, as returned by LibraryController::show()'s `shares.user:id,name,email` eager load (briefing 4.3). */
interface Share {
  scope: 'guest' | 'all_users' | 'user'
  user_id: number | null
  user: { id: number; name: string } | null
  /** GitHub issue #79 — a deliberate extension beyond briefing 4.3's originally read-only shares; always present (the backend defaults it to 'read'), never null. */
  access_level: 'read' | 'write'
}

/** Exported for LibrarySettingsDialog.tsx, which edits every field here (GitHub issue #76). */
export interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
  shares?: Share[]
}

/** GET /api/users (UserController::shareable()) — the share-target picker's option list (LibrarySettingsDialog.tsx's sharing/ownership sections). */
export interface ShareableUser {
  id: number
  name: string
}

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

/**
 * A single library's contents (briefing 5.) — reachable from the "view"
 * link in LibrariesPage.tsx. GET /libraries/{id} already applies
 * LibraryAccessService::canRead() server-side, so a library this user
 * can't see 403s and nothing further is rendered.
 */
export function LibraryDetailPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { id } = useParams<{ id: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const [library, setLibrary] = useState<Library | null>(null)
  const [items, setItems] = useState<Paginated<MediaItem> | null>(null)
  const [libraries, setLibraries] = useState<Library[]>([])
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  // Manual creation (briefing 7.1, GitHub issue #17) — a standalone entry
  // point independent of the capture/scan workflow, so an item can be added
  // even without ever attempting a scan first.
  const [creating, setCreating] = useState(false)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  // GitHub issue #108 — a lighter-weight indicator for loadItems() below,
  // which (unlike `loading`) never blanks the rest of the page; only the
  // item table's own heading gets a subtle "…" while it's in flight.
  const [itemsLoading, setItemsLoading] = useState(false)
  // GitHub issue #108 — previously missing entirely: a failed load left
  // `loading` stuck at `true` forever (setLoading(false) never ran), the
  // same gap SearchPage.tsx/StatisticsPage.tsx/ReportDetailPage.tsx already
  // got fixed for.
  const [error, setError] = useState<string | null>(null)

  // Column sorting (GitHub issue #77) — resolved server-side
  // (MediaItemController::index()'s sort_by/sort_dir) rather than sorting
  // just the current page client-side, since items are paginated and a
  // client-side sort would silently only reorder whichever rows happened to
  // already be on the current page. sortBy is null until a header is
  // clicked, matching the previous unsorted default.
  const [sortBy, setSortBy] = useState<string | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')

  // Bulk delete (GitHub issue #54) — a selection mode over the current
  // page of items.data, entered/exited explicitly rather than always-on
  // checkboxes, so the ordinary "click a row to open it" interaction stays
  // the default. Selection is intentionally page-scoped (`items.data` is
  // only ever one page at a time, see the Paginated<T> type above) rather
  // than tracked across page changes — cleared on every page/library
  // change below, same as bulkDeleteError.
  const [bulkMode, setBulkMode] = useState(false)
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const [bulkDeleteError, setBulkDeleteError] = useState<string | null>(null)

  // Bulk field update (GitHub issue #63) — the general follow-up #54 itself
  // proposed: set one field to one shared value across every selected item.
  // `bulkEditField` defaults to the first bulk-editable field once a library
  // loads (see the effect below), so the value input always has a concrete
  // field/type to render against rather than needing its own "nothing
  // selected yet" placeholder state.
  const [bulkEditField, setBulkEditField] = useState<string>('')
  const [bulkEditValue, setBulkEditValue] = useState('')
  const [bulkUpdateError, setBulkUpdateError] = useState<string | null>(null)

  // GET /api/users — the share-target/new-owner picker's option list for
  // LibrarySettingsDialog.tsx, fetched here since it's needed as soon as
  // the dialog opens rather than only once the dialog itself mounts.
  const [shareableUsers, setShareableUsers] = useState<ShareableUser[]>([])

  // Library settings dialog (name/description, sharing briefing 4.3/GitHub
  // issue #32, ownership transfer GitHub issue #34) — consolidated behind
  // the single "Bearbeiten" button into LibrarySettingsDialog.tsx (GitHub
  // issue #76) instead of an inline edit form plus two separate always-
  // visible page sections.
  const [editingSettings, setEditingSettings] = useState(false)

  /**
   * Everything this page needs (library metadata, the current page of
   * items, the cross-library move-target list, the shareable-user list) —
   * used for the initial load, whenever the library itself changes (id),
   * and after settings are saved (LibrarySettingsDialog's onSaved, since
   * only that action can change the library's own name/description/
   * sharing). Takes page/sortBy/sortDir as explicit parameters rather than
   * reading them from state (GitHub issue #108): the id-change effect
   * below calls this in the same tick it resets page/sortBy/sortDir back
   * to their defaults, before that reset has actually re-rendered —
   * reading state here would still see the *previous* library's stale
   * values.
   */
  async function loadAll(pageParam: number, sortByParam: string | null, sortDirParam: 'asc' | 'desc') {
    setLoading(true)
    setError(null)
    try {
      const [libraryRes, itemsRes, librariesRes, shareableUsersRes] = await Promise.all([
        apiClient.get<Library>(`/libraries/${id}`),
        apiClient.get<Paginated<MediaItem>>(`/libraries/${id}/items`, {
          params: { page: pageParam, ...(sortByParam ? { sort_by: sortByParam, sort_dir: sortDirParam } : {}) },
        }),
        // Needed for the detail dialog's "move to another library" target list
        // (only libraries visible to this user are returned to begin with).
        apiClient.get<Library[]>('/libraries'),
        apiClient.get<ShareableUser[]>('/users'),
      ])
      setLibrary(libraryRes.data)
      setItems(itemsRes.data)
      setLibraries(librariesRes.data)
      setShareableUsers(shareableUsersRes.data)
    } catch (err) {
      setError(describeError(err, t))
    } finally {
      setLoading(false)
    }
  }

  /**
   * GitHub issue #108: reloads just the item list. Sorting a column or
   * turning a page never changes the library's own metadata, the
   * cross-library move-target list, or the shareable-user list, so
   * re-fetching all four via loadAll() on every such click was wasted work
   * that also blanked the entire page — `loading` gates everything
   * rendered below, not just the table. Also reused by item mutations that
   * only ever affect the item list itself (creating/deleting/bulk-editing
   * items below) for the same reason.
   */
  async function loadItems(pageParam: number, sortByParam: string | null, sortDirParam: 'asc' | 'desc') {
    setItemsLoading(true)
    setError(null)
    try {
      const { data } = await apiClient.get<Paginated<MediaItem>>(`/libraries/${id}/items`, {
        params: { page: pageParam, ...(sortByParam ? { sort_by: sortByParam, sort_dir: sortDirParam } : {}) },
      })
      setItems(data)
    } catch (err) {
      setError(describeError(err, t))
    } finally {
      setItemsLoading(false)
    }
  }

  function resetBulkState() {
    setSelectedIds(new Set())
    setBulkDeleteError(null)
    setBulkEditValue('')
    setBulkUpdateError(null)
  }

  // GitHub issue #108: the page/sort effect below would otherwise also
  // fire right after the id effect resets page/sortBy/sortDir back to
  // their defaults for the newly opened library — this flag tells it to
  // skip that one redundant run, since loadAll() below already loads the
  // (correctly reset) first page unsorted.
  const skipNextItemsReload = useRef(false)

  // A different library entirely (id changed) — full reload, and reset
  // pagination/sort back to defaults rather than carrying over whatever
  // page/column the *previous* library happened to be on (GitHub issue
  // #108): a smaller library could otherwise open on a page past its own
  // last page and wrongly appear empty.
  useEffect(() => {
    skipNextItemsReload.current = true
    setPage(1)
    setSortBy(null)
    setSortDir('asc')
    void loadAll(1, null, 'asc')
    resetBulkState()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  // Same library, just a different page or sort column (GitHub issue
  // #108) — see loadItems()'s own docblock for why this doesn't repeat
  // the id effect's full reload.
  useEffect(() => {
    if (skipNextItemsReload.current) {
      skipNextItemsReload.current = false
      return
    }
    void loadItems(page, sortBy, sortDir)
    resetBulkState()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, sortBy, sortDir])

  /** Clicking a sortable column header (GitHub issue #77) — same column toggles asc/desc, a different one starts at asc, either way back to page 1 since the sort applies across the whole result set, not just this page. */
  function handleSort(column: string) {
    if (sortBy === column) {
      setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortDir('asc')
    }
    setPage(1)
  }

  // Deep-links an item straight into MediaItemDetailDialog via ?item=<id>
  // (SearchPage.tsx's results link here) — fetched on its own via
  // GET .../items/{item} rather than requiring it to already be on the
  // currently loaded page of items.data, since a search hit's page within
  // this library's full item list isn't known ahead of time.
  useEffect(() => {
    const itemParam = searchParams.get('item')
    if (!itemParam || !id) return
    apiClient
      .get<MediaItem>(`/libraries/${id}/items/${itemParam}`)
      .then(({ data }) => setSelectedItem(data))
      .catch(() => {
        // Stale/invalid link (deleted item, no access, ...) — drop the
        // param rather than leaving the page stuck retrying it forever.
        setSearchParams((prev) => {
          const next = new URLSearchParams(prev)
          next.delete('item')
          return next
        })
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, searchParams.get('item')])

  function toggleSelected(itemId: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(itemId)) {
        next.delete(itemId)
      } else {
        next.add(itemId)
      }
      return next
    })
  }

  function toggleSelectAll() {
    if (!items) return
    setSelectedIds((prev) => (prev.size === items.data.length ? new Set() : new Set(items.data.map((i) => i.id))))
  }

  /** A row's click target (GitHub issue #77) — toggles its checkbox in bulk mode, otherwise opens MediaItemDetailDialog. Shared by the row itself and the title cell's button so both trigger the exact same action instead of two subtly different ones. */
  function activateRow(item: MediaItem) {
    if (bulkMode) {
      toggleSelected(item.id)
    } else {
      setSelectedItem(item)
    }
  }

  /** POST .../items/bulk-delete (GitHub issue #54) — mirrors MediaItemDetailDialog's single-item delete: confirm, then reload rather than splice, since a bulk delete can shift this page's remaining items/pagination in a way that's simplest to just re-fetch (same reasoning CreateMediaItemDialog's onCreated already documents). */
  async function deleteSelected() {
    if (selectedIds.size === 0) return
    if (!window.confirm(t('mediaItem.bulkDelete.confirm', { count: selectedIds.size }))) return
    setBulkDeleteError(null)
    try {
      await apiClient.post(`/libraries/${id}/items/bulk-delete`, { ids: Array.from(selectedIds) })
      setSelectedIds(new Set())
      await loadItems(page, sortBy, sortDir)
    } catch (err) {
      setBulkDeleteError(describeError(err, t))
    }
  }

  /**
   * POST .../items/bulk-update (GitHub issue #63) — sets `bulkEditField` to
   * `bulkEditValue` across every selected item. `payloadFromValues()` does
   * the same string -> typed-value conversion (empty string -> null,
   * `type: 'number'` -> Number(...)) the single-item edit form already
   * uses, called here with just the one active field's spec so a bulk
   * "clear this field" (an empty value input) round-trips to the backend
   * as an explicit null exactly like a single-item edit's does, not an
   * accidentally-omitted key.
   */
  async function updateSelected() {
    if (selectedIds.size === 0 || !bulkEditFieldSpec) return
    if (!window.confirm(t('mediaItem.bulkUpdate.confirm', { count: selectedIds.size }))) return
    setBulkUpdateError(null)
    try {
      const value = payloadFromValues({ [bulkEditFieldSpec.key]: bulkEditValue }, [bulkEditFieldSpec])[bulkEditFieldSpec.key]
      await apiClient.post(`/libraries/${id}/items/bulk-update`, { ids: Array.from(selectedIds), field: bulkEditFieldSpec.key, value })
      setSelectedIds(new Set())
      setBulkEditValue('')
      await loadItems(page, sortBy, sortDir)
    } catch (err) {
      setBulkUpdateError(describeError(err, t))
    }
  }

  // GitHub issue #108: `loading` no longer gets stuck forever on a failed
  // loadAll() (see that function's own try/finally), but `library` itself
  // would otherwise stay null forever in that case too — showing a plain
  // "…" with no way back and no explanation. A way back to the library
  // list plus the actual error message is more useful than an
  // indefinitely-stuck loading indicator.
  if (loading) return <p className="hint">…</p>
  if (!library) {
    return (
      <div className="panel-page">
        <p>
          <Link to="/libraries">← {t('libraries.title')}</Link>
        </p>
        {error && <p role="alert">{error}</p>}
      </div>
    )
  }

  // Mirrors LibraryAccessService::canWrite() (admin or owner) — same client-side pattern as LibrariesPage.tsx's canDelete().
  // Gates the "Bearbeiten" button that opens LibrarySettingsDialog.tsx (GitHub issue #76) — name/description, sharing and ownership are all owner/admin-only.
  const canManage = user?.level === 'admin' || library.owner.id === user?.id

  // GitHub issue #63: mirrors MediaItemController::bulkUpdate()'s own field
  // exclusion — `runtime_seconds` (CD, #48) is only ever derived from
  // `tracks`, which itself was never part of FIELD_SPECS to begin with (not
  // a single scalar value a plain input can represent), so this is the one
  // FIELD_SPECS entry that still needs filtering out here.
  const bulkEditableFields = FIELD_SPECS[library.media_type].filter((f) => f.key !== 'runtime_seconds')
  const bulkEditFieldSpec = bulkEditableFields.find((f) => f.key === bulkEditField)

  return (
    <div className="panel-page">
      <p>
        <Link to="/libraries">← {t('libraries.title')}</Link>
      </p>

      <header className="panel-page__header">
        <h1>{library.name}</h1>
        <p className="library-detail__meta">
          <span className="media-type-badge">{t(`libraries.mediaType.${library.media_type}`)}</span>
          <span className="hint">{library.owner.name}</span>
        </p>
        {library.description && <p className="hint">{library.description}</p>}
        {/* Opens LibrarySettingsDialog.tsx (GitHub issue #76) — name/description
            editing, sharing (briefing 4.3) and ownership transfer (GitHub issue
            #34) all live behind this one button now, instead of an inline edit
            form plus two separate always-visible page sections. */}
        {canManage && (
          <button type="button" onClick={() => setEditingSettings(true)}>
            {t('admin.actions.edit')}
          </button>
        )}
      </header>

      {error && <p role="alert">{error}</p>}

      <section className="panel-card">
        <h2>
          {t('libraries.itemsTitle', { count: items?.total ?? 0 })}
          {itemsLoading && ' …'}
        </h2>

        <div className="library-items-toolbar">
          {/* GitHub issue #87: a printable/archivable PDF inventory list of
              this library — a read action like the rest of this page, so
              available regardless of canManage (a guest with read access to
              a shared library can export it too, same as they can already
              browse it). Plain navigation rather than an apiClient request,
              same window.location.href pattern BackupsPage.tsx's download
              button already uses for a GET file download. */}
          <button
            type="button"
            onClick={() => {
              window.location.href = `${apiClient.defaults.baseURL}/libraries/${library.id}/export/pdf`
            }}
          >
            {t('libraries.exportPdf')}
          </button>
          {canManage && (
            <button type="button" onClick={() => setCreating(true)}>
              {t('mediaItem.addManually')}
            </button>
          )}
          {canManage && items && items.data.length > 0 && (
            <button
              type="button"
              onClick={() => {
                setBulkMode((prev) => !prev)
                setSelectedIds(new Set())
                setBulkDeleteError(null)
                setBulkEditField(bulkEditableFields[0]?.key ?? '')
                setBulkEditValue('')
                setBulkUpdateError(null)
              }}
            >
              {t(bulkMode ? 'mediaItem.bulkDelete.exit' : 'mediaItem.bulkDelete.enter')}
            </button>
          )}
        </div>

        {bulkMode && items && items.data.length > 0 && (
          <p className="media-item-list__bulk-bar">
            <label>
              <input
                type="checkbox"
                checked={selectedIds.size === items.data.length}
                onChange={toggleSelectAll}
              />
              {t(selectedIds.size === items.data.length ? 'mediaItem.bulkDelete.deselectAll' : 'mediaItem.bulkDelete.selectAll')}
            </label>{' '}
            <button type="button" disabled={selectedIds.size === 0} onClick={() => void deleteSelected()}>
              {t('mediaItem.bulkDelete.deleteSelected', { count: selectedIds.size })}
            </button>
            {bulkDeleteError && <span role="alert"> {bulkDeleteError}</span>}
          </p>
        )}

        {/* GitHub issue #63: sets one field to one shared value across every selected item, alongside the delete bar above. */}
        {bulkMode && items && items.data.length > 0 && (
          <p className="media-item-list__bulk-bar">
            <select className="panel-select" value={bulkEditField} onChange={(e) => { setBulkEditField(e.target.value); setBulkEditValue('') }}>
              {bulkEditableFields.map((f) => (
                <option key={f.key} value={f.key}>
                  {t(`mediaItem.fields.${f.key}`)}
                </option>
              ))}
            </select>
            {bulkEditFieldSpec?.type === 'textarea' ? (
              <textarea value={bulkEditValue} onChange={(e) => setBulkEditValue(e.target.value)} />
            ) : (
              <input
                type={bulkEditFieldSpec?.type ?? 'text'}
                step={bulkEditFieldSpec?.type === 'number' ? 'any' : undefined}
                value={bulkEditValue}
                onChange={(e) => setBulkEditValue(e.target.value)}
              />
            )}
            <button type="button" disabled={selectedIds.size === 0} onClick={() => void updateSelected()}>
              {t('mediaItem.bulkUpdate.apply', { count: selectedIds.size })}
            </button>
            {bulkUpdateError && <span role="alert"> {bulkUpdateError}</span>}
          </p>
        )}

        {items && items.data.length === 0 ? (
          <p className="hint">{t('libraries.noItems')}</p>
        ) : (
          // A plain <table> inside .panel-card (same pattern as
          // LanguagesPage.tsx's installed-packs table) — .panel-card's own
          // overflow-x: auto already scopes horizontal scrolling to the
          // card on a narrow viewport, so no extra wrapper/class is needed
          // for the mobile behavior the issue asked for.
          <table className="media-item-table">
            <thead>
              <tr>
                {bulkMode && (
                  <th className="media-item-table__col--checkbox">
                    <input type="checkbox" checked={selectedIds.size === (items?.data.length ?? 0)} onChange={toggleSelectAll} aria-label={t('mediaItem.bulkDelete.selectAll')} />
                  </th>
                )}
                <th aria-hidden="true" />
                <SortableHeader column="title" label={t('mediaItem.fields.title')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                <SortableHeader column={subtitleField(library.media_type)} label={t(`mediaItem.fields.${subtitleField(library.media_type)}`)} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                <SortableHeader column="ean" label={t('mediaItem.fields.ean')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                {/* CD-only columns (GitHub issue #98) — already part of every row's payload (MediaItemController::index() serializes the full model, no field selection), so no extra fetch is needed. */}
                {library.media_type === 'cd' && (
                  <>
                    <SortableHeader column="runtime_seconds" label={t('mediaItem.runtime')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                    <th>{t('mediaItem.trackCount')}</th>
                    <SortableHeader column="release_date" label={t('mediaItem.fields.release_date')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                  </>
                )}
                {/* location (GitHub issue #96) applies to every media type, unlike the CD-only columns above — GitHub issue #108. */}
                <SortableHeader column="location" label={t('mediaItem.fields.location')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
              </tr>
            </thead>
            <tbody>
              {items?.data.map((item) => (
                // Clicking anywhere on the row opens MediaItemDetailDialog
                // (or toggles the checkbox in bulk mode, GitHub issue #54);
                // the title cell additionally renders a real <button> for
                // the same action so it stays keyboard/screen-reader
                // operable without needing a non-interactive <tr> to carry
                // its own key handling.
                <tr key={item.id} className="media-item-table__row" onClick={() => activateRow(item)}>
                  {bulkMode && (
                    <td className="media-item-table__col--checkbox">
                      <input
                        type="checkbox"
                        aria-label={item.title}
                        checked={selectedIds.has(item.id)}
                        onClick={(e) => e.stopPropagation()}
                        onChange={() => toggleSelected(item.id)}
                      />
                    </td>
                  )}
                  <td>
                    {/* The small generated thumbnail (MediaItemController::coverThumbnail()),
                        not the full cover — this list can hold many rows, and CoverDownloadService
                        already generates one alongside every stored cover for exactly this.
                        Served through the API, not a direct storage URL — see
                        CoverDownloadService's docblock for why — so it needs the session
                        cookie even cross-origin in local dev. */}
                    {item.cover_path && (
                      <img
                        className="media-item-table__cover"
                        src={`${apiClient.defaults.baseURL}/libraries/${library.id}/items/${item.id}/cover/thumbnail`}
                        crossOrigin="use-credentials"
                        alt=""
                      />
                    )}
                  </td>
                  <td>
                    <button type="button" className="media-item-table__title-button" onClick={(e) => { e.stopPropagation(); activateRow(item) }}>
                      {item.title}
                    </button>
                  </td>
                  <td>{item[subtitleField(library.media_type)] ?? ''}</td>
                  <td>{item.ean}</td>
                  {library.media_type === 'cd' && (
                    <>
                      <td>{item.runtime_seconds != null ? formatDuration(item.runtime_seconds) : ''}</td>
                      <td>{item.tracks?.length ?? ''}</td>
                      <td>{item.release_date ? dateOnly(item.release_date) : ''}</td>
                    </>
                  )}
                  <td>{item.location ?? ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {items && items.last_page > 1 && (
          <div className="library-pagination">
            <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              ←
            </button>
            <span>
              {items.current_page} / {items.last_page}
            </span>
            <button type="button" disabled={page >= items.last_page} onClick={() => setPage((p) => p + 1)}>
              →
            </button>
          </div>
        )}
      </section>

      <LibrarySettingsDialog
        library={library}
        shareableUsers={shareableUsers}
        open={editingSettings}
        onClose={() => setEditingSettings(false)}
        onSaved={() => void loadAll(page, sortBy, sortDir)}
      />

      <CreateMediaItemDialog
        library={library}
        open={creating}
        onClose={() => setCreating(false)}
        onCreated={() => {
          setCreating(false)
          // Re-fetches instead of splicing the new item into items.data
          // directly — a fresh item can land on any page depending on the
          // list's current sort/pagination, so reloading is the only way
          // to reflect it (and the updated total) correctly. Only the item
          // list itself needs it (GitHub issue #108) — creating an item
          // never changes the library's own metadata, the move-target
          // list, or the shareable-user list.
          void loadItems(page, sortBy, sortDir)
        }}
      />

      <MediaItemDetailDialog
        library={library}
        item={selectedItem}
        libraries={libraries}
        onClose={() => {
          setSelectedItem(null)
          // Drops ?item=<id> (if present, e.g. arrived here from a search
          // result) so closing the dialog doesn't leave a stale deep link
          // in the address bar that would just reopen it on a refresh.
          if (searchParams.has('item')) {
            setSearchParams((prev) => {
              const next = new URLSearchParams(prev)
              next.delete('item')
              return next
            })
          }
        }}
        onUpdated={(updated) => {
          setItems((prev) => (prev ? { ...prev, data: prev.data.map((i) => (i.id === updated.id ? updated : i)) } : prev))
          setSelectedItem(updated)
        }}
        onDeleted={() => {
          setItems((prev) => (prev ? { ...prev, data: prev.data.filter((i) => i.id !== selectedItem?.id), total: prev.total - 1 } : prev))
          setSelectedItem(null)
        }}
        onMoved={() => {
          setItems((prev) => (prev ? { ...prev, data: prev.data.filter((i) => i.id !== selectedItem?.id), total: prev.total - 1 } : prev))
          setSelectedItem(null)
        }}
      />
    </div>
  )
}
