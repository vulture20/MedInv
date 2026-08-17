import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { describeError } from '../admin/adminErrors'
import { MediaItemDetailDialog, type MediaItem } from './MediaItemDetailDialog'
import { CreateMediaItemDialog } from './CreateMediaItemDialog'
import { FIELD_SPECS, payloadFromValues } from './mediaItemFields'

/** One row of App\Models\LibraryShare, as returned by LibraryController::show()'s `shares.user:id,name,email` eager load (briefing 4.3). */
interface Share {
  scope: 'guest' | 'all_users' | 'user'
  user_id: number | null
  user: { id: number; name: string } | null
}

interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
  shares?: Share[]
}

/** GET /api/users (UserController::shareable()) — the share-target picker's option list. */
interface ShareableUser {
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
 * The item table's second column, media-type dependent (briefing 6.) — the
 * field key itself, not just its displayed value, since it doubles as the
 * `sort_by` value sent to GET .../items (GitHub issue #77) and mirrors
 * MediaItemController::SORTABLE_COLUMNS.
 */
function subtitleField(mediaType: Library['media_type']): 'authors' | 'artist' | 'director' {
  switch (mediaType) {
    case 'book':
      return 'authors'
    case 'cd':
      return 'artist'
    case 'dvd_bluray':
      return 'director'
  }
}

/**
 * One clickable, sortable `<th>` in the item table (GitHub issue #77) —
 * a plain `<button>` filling the header cell so the sort toggle stays
 * keyboard/screen-reader operable, same reasoning as
 * media-item-table__title-button below. `aria-sort` reflects the *current*
 * state (not just "this column is sortable") per the WAI-ARIA table
 * sorting pattern, so assistive tech announces which column and direction
 * is active without relying on the visual ▲/▼ glyph alone.
 */
function SortableHeader({
  column,
  label,
  sortBy,
  sortDir,
  onSort,
}: {
  column: string
  label: string
  sortBy: string | null
  sortDir: 'asc' | 'desc'
  onSort: (column: string) => void
}) {
  const active = sortBy === column
  return (
    <th aria-sort={active ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
      <button type="button" className="media-item-table__sort-button" onClick={() => onSort(column)}>
        {label}
        {active && <span aria-hidden="true"> {sortDir === 'asc' ? '▲' : '▼'}</span>}
      </button>
    </th>
  )
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

  // Library sharing (briefing 4.3, GitHub issue #32) — editable local state,
  // synced from `library.shares` whenever a fresh library loads (below),
  // submitted as one combined array to PUT /libraries/{id}/shares on save
  // (that endpoint replaces the full share list, it doesn't add/remove
  // incrementally).
  const [shareableUsers, setShareableUsers] = useState<ShareableUser[]>([])
  const [guestShare, setGuestShare] = useState(false)
  const [allUsersShare, setAllUsersShare] = useState(false)
  const [userShares, setUserShares] = useState<{ user_id: number; name: string }[]>([])
  const [addUserId, setAddUserId] = useState<number | ''>('')
  const [sharesSaved, setSharesSaved] = useState(false)
  const [sharesError, setSharesError] = useState<string | null>(null)

  // Ownership transfer (GitHub issue #34) — reuses the same shareable-users
  // list as sharing above; the picker only offers non-guest, non-self
  // accounts, matching who's actually allowed to own a library.
  const [newOwnerId, setNewOwnerId] = useState<number | ''>('')
  const [ownerTransferError, setOwnerTransferError] = useState<string | null>(null)

  // Editing name/description (briefing 5., restricted to owner/admin like
  // sharing and ownership above) — PUT /libraries/{id} already existed
  // server-side (LibraryController::update()) but had no UI until now.
  const [editingInfo, setEditingInfo] = useState(false)
  const [editName, setEditName] = useState('')
  const [editDescription, setEditDescription] = useState('')
  const [infoError, setInfoError] = useState<string | null>(null)

  async function load() {
    setLoading(true)
    const [libraryRes, itemsRes, librariesRes, shareableUsersRes] = await Promise.all([
      apiClient.get<Library>(`/libraries/${id}`),
      apiClient.get<Paginated<MediaItem>>(`/libraries/${id}/items`, {
        params: { page, ...(sortBy ? { sort_by: sortBy, sort_dir: sortDir } : {}) },
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
    setGuestShare(libraryRes.data.shares?.some((s) => s.scope === 'guest') ?? false)
    setAllUsersShare(libraryRes.data.shares?.some((s) => s.scope === 'all_users') ?? false)
    setUserShares(
      (libraryRes.data.shares ?? [])
        .filter((s) => s.scope === 'user' && s.user)
        .map((s) => ({ user_id: s.user!.id, name: s.user!.name })),
    )
    setSharesSaved(false)
    setSharesError(null)
    setLoading(false)
  }

  useEffect(() => {
    void load()
    setSelectedIds(new Set())
    setBulkDeleteError(null)
    setBulkEditValue('')
    setBulkUpdateError(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, page, sortBy, sortDir])

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

  async function saveShares(e: React.FormEvent) {
    e.preventDefault()
    if (!library) return
    setSharesError(null)
    setSharesSaved(false)
    const shares = [
      ...(guestShare ? [{ scope: 'guest' }] : []),
      ...(allUsersShare ? [{ scope: 'all_users' }] : []),
      ...userShares.map((s) => ({ scope: 'user', user_id: s.user_id })),
    ]
    try {
      await apiClient.put(`/libraries/${library.id}/shares`, { shares })
      setSharesSaved(true)
    } catch (err) {
      setSharesError(describeError(err, t))
    }
  }

  function addUserShare() {
    if (addUserId === '') return
    const target = shareableUsers.find((u) => u.id === addUserId)
    if (!target) return
    setUserShares((prev) => [...prev, { user_id: target.id, name: target.name }])
    setAddUserId('')
  }

  function startEditInfo() {
    if (!library) return
    setEditName(library.name)
    setEditDescription(library.description ?? '')
    setInfoError(null)
    setEditingInfo(true)
  }

  async function saveInfo(e: React.FormEvent) {
    e.preventDefault()
    if (!library) return
    setInfoError(null)
    try {
      const { data } = await apiClient.put<Library>(`/libraries/${library.id}`, {
        name: editName,
        description: editDescription === '' ? null : editDescription,
      })
      setLibrary((prev) => (prev ? { ...prev, name: data.name, description: data.description } : prev))
      setEditingInfo(false)
    } catch (err) {
      setInfoError(describeError(err, t))
    }
  }

  async function transferOwnership() {
    if (!library || newOwnerId === '') return
    const target = shareableUsers.find((u) => u.id === newOwnerId)
    if (!target) return
    if (!window.confirm(t('libraries.ownership.confirm', { name: target.name }))) return
    setOwnerTransferError(null)
    try {
      await apiClient.put(`/libraries/${library.id}/owner`, { owner_id: newOwnerId })
      setNewOwnerId('')
      // Re-fetches the library with its new owner — canManage below
      // depends on it, so the sharing/ownership sections (and the item
      // list, if the current user no longer has write access at all)
      // reflect the change immediately rather than only after a manual reload.
      await load()
    } catch (err) {
      setOwnerTransferError(describeError(err, t))
    }
  }

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
      await load()
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
      await load()
    } catch (err) {
      setBulkUpdateError(describeError(err, t))
    }
  }

  if (loading || !library) return <p>…</p>

  // Mirrors LibraryAccessService::canWrite() (admin or owner) — same client-side pattern as LibrariesPage.tsx's canDelete().
  // Shared by the edit-info, sharing and ownership sections below — all three are owner/admin-only.
  const canManage = user?.level === 'admin' || library.owner.id === user?.id
  const usersAvailableToAdd = shareableUsers.filter((u) => !userShares.some((s) => s.user_id === u.id))

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

      {editingInfo ? (
        <section className="panel-card">
          <h2>{t('admin.actions.edit')}</h2>
          <form onSubmit={(e) => void saveInfo(e)}>
            <label>
              {t('common.name')}
              <input className="panel-select" value={editName} onChange={(e) => setEditName(e.target.value)} required />
            </label>
            <label>
              {t('libraries.descriptionLabel')}
              <textarea className="panel-select" value={editDescription} onChange={(e) => setEditDescription(e.target.value)} />
            </label>
            <div>
              <button type="submit">{t('admin.actions.save')}</button>{' '}
              <button type="button" onClick={() => setEditingInfo(false)}>
                {t('admin.actions.cancel')}
              </button>
            </div>
            {infoError && <p role="alert">{infoError}</p>}
          </form>
        </section>
      ) : (
        <header className="panel-page__header">
          <h1>{library.name}</h1>
          <p className="library-detail__meta">
            <span className="media-type-badge">{t(`libraries.mediaType.${library.media_type}`)}</span>
            <span className="hint">{library.owner.name}</span>
          </p>
          {library.description && <p className="hint">{library.description}</p>}
          {canManage && (
            <button type="button" onClick={startEditInfo}>
              {t('admin.actions.edit')}
            </button>
          )}
        </header>
      )}

      {canManage && (
        <section className="panel-card">
          <h2>{t('libraries.sharing.title')}</h2>
          <p className="hint">{t('libraries.sharing.hint')}</p>
          <form onSubmit={(e) => void saveShares(e)}>
            <label>
              <input type="checkbox" checked={guestShare} onChange={(e) => setGuestShare(e.target.checked)} />
              {t('libraries.sharing.guests')}
            </label>
            <label>
              <input type="checkbox" checked={allUsersShare} onChange={(e) => setAllUsersShare(e.target.checked)} />
              {t('libraries.sharing.allUsers')}
            </label>

            <div>
              <h3>{t('libraries.sharing.specificUsers')}</h3>
              {userShares.length === 0 ? (
                <p className="hint">{t('libraries.sharing.noSpecificUsers')}</p>
              ) : (
                <ul>
                  {userShares.map((share) => (
                    <li key={share.user_id}>
                      {share.name}{' '}
                      <button
                        type="button"
                        onClick={() => setUserShares((prev) => prev.filter((s) => s.user_id !== share.user_id))}
                      >
                        {t('libraries.sharing.remove')}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {usersAvailableToAdd.length > 0 && (
                <p>
                  <select className="panel-select" value={addUserId} onChange={(e) => setAddUserId(e.target.value ? Number(e.target.value) : '')}>
                    <option value="">{t('libraries.sharing.selectUser')}</option>
                    {usersAvailableToAdd.map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name}
                      </option>
                    ))}
                  </select>{' '}
                  <button type="button" disabled={addUserId === ''} onClick={addUserShare}>
                    {t('libraries.sharing.add')}
                  </button>
                </p>
              )}
            </div>

            <button type="submit">{t('admin.actions.save')}</button>
            {sharesSaved && (
              <p role="status" className="panel-confirmation">
                {t('libraries.sharing.saved')}
              </p>
            )}
            {sharesError && <p role="alert">{sharesError}</p>}
          </form>
        </section>
      )}

      {canManage && shareableUsers.length > 0 && (
        <section className="panel-card">
          <h2>{t('libraries.ownership.title')}</h2>
          <p className="hint">{t('libraries.ownership.hint')}</p>
          <p>
            <select className="panel-select" value={newOwnerId} onChange={(e) => setNewOwnerId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">{t('libraries.ownership.selectUser')}</option>
              {shareableUsers.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name}
                </option>
              ))}
            </select>{' '}
            <button type="button" disabled={newOwnerId === ''} onClick={() => void transferOwnership()}>
              {t('libraries.ownership.transfer')}
            </button>
          </p>
          {ownerTransferError && <p role="alert">{ownerTransferError}</p>}
        </section>
      )}

      <section className="panel-card">
        <h2>{t('libraries.itemsTitle', { count: items?.total ?? 0 })}</h2>

        <div className="library-items-toolbar">
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

      <CreateMediaItemDialog
        library={library}
        open={creating}
        onClose={() => setCreating(false)}
        onCreated={() => {
          setCreating(false)
          // Re-fetches instead of splicing the new item into items.data
          // directly — a fresh item can land on any page depending on the
          // list's current sort/pagination, so reloading is the only way
          // to reflect it (and the updated total) correctly.
          void load()
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
