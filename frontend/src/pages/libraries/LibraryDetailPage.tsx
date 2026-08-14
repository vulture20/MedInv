import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { describeError } from '../admin/adminErrors'
import { MediaItemDetailDialog, type MediaItem } from './MediaItemDetailDialog'

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

/** The secondary line under a media item's title, media-type dependent (briefing 6.). */
function subtitle(item: MediaItem, mediaType: Library['media_type']): string | null {
  switch (mediaType) {
    case 'book':
      return item.authors ?? null
    case 'cd':
      return item.artist ?? null
    case 'dvd_bluray':
      return item.director ?? null
  }
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
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)

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
      apiClient.get<Paginated<MediaItem>>(`/libraries/${id}/items`, { params: { page } }),
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, page])

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

  if (loading || !library) return <p>…</p>

  // Mirrors LibraryAccessService::canWrite() (admin or owner) — same client-side pattern as LibrariesPage.tsx's canDelete().
  // Shared by the edit-info, sharing and ownership sections below — all three are owner/admin-only.
  const canManage = user?.level === 'admin' || library.owner.id === user?.id
  const usersAvailableToAdd = shareableUsers.filter((u) => !userShares.some((s) => s.user_id === u.id))

  return (
    <div>
      <p>
        <Link to="/libraries">{t('libraries.title')}</Link>
      </p>

      {editingInfo ? (
        <form onSubmit={(e) => void saveInfo(e)}>
          <label>
            {t('common.name')}
            <input value={editName} onChange={(e) => setEditName(e.target.value)} required />
          </label>
          <label>
            {t('libraries.descriptionLabel')}
            <textarea value={editDescription} onChange={(e) => setEditDescription(e.target.value)} />
          </label>
          <div>
            <button type="submit">{t('admin.actions.save')}</button>{' '}
            <button type="button" onClick={() => setEditingInfo(false)}>
              {t('admin.actions.cancel')}
            </button>
          </div>
          {infoError && <p role="alert">{infoError}</p>}
        </form>
      ) : (
        <>
          <h1>{library.name}</h1>
          <p>
            {t(`libraries.mediaType.${library.media_type}`)} — {library.owner.name}
          </p>
          {library.description && <p>{library.description}</p>}
          {canManage && (
            <p>
              <button type="button" onClick={startEditInfo}>
                {t('admin.actions.edit')}
              </button>
            </p>
          )}
        </>
      )}

      {canManage && (
        <section>
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
                  <select value={addUserId} onChange={(e) => setAddUserId(e.target.value ? Number(e.target.value) : '')}>
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
            {sharesSaved && <p role="status">{t('libraries.sharing.saved')}</p>}
            {sharesError && <p role="alert">{sharesError}</p>}
          </form>
        </section>
      )}

      {canManage && shareableUsers.length > 0 && (
        <section>
          <h2>{t('libraries.ownership.title')}</h2>
          <p className="hint">{t('libraries.ownership.hint')}</p>
          <p>
            <select value={newOwnerId} onChange={(e) => setNewOwnerId(e.target.value ? Number(e.target.value) : '')}>
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

      {items && items.data.length === 0 ? (
        <p>{t('libraries.noItems')}</p>
      ) : (
        <ul className="media-item-list">
          {items?.data.map((item) => (
            <li key={item.id}>
              {/* Opens MediaItemDetailDialog (view/edit/delete/move) below. */}
              <button type="button" className="media-item-list__row" onClick={() => setSelectedItem(item)}>
                {/* The small generated thumbnail (MediaItemController::coverThumbnail()),
                    not the full cover — this list can hold many rows, and CoverDownloadService
                    already generates one alongside every stored cover for exactly this.
                    Served through the API, not a direct storage URL — see
                    CoverDownloadService's docblock for why — so it needs the session
                    cookie even cross-origin in local dev. */}
                {item.cover_path && (
                  <img
                    className="media-item-list__cover"
                    src={`${apiClient.defaults.baseURL}/libraries/${library.id}/items/${item.id}/cover/thumbnail`}
                    crossOrigin="use-credentials"
                    alt=""
                  />
                )}
                <strong>{item.title}</strong>
                {subtitle(item, library.media_type) && <> — {subtitle(item, library.media_type)}</>}
                {' — '}
                {item.ean}
              </button>
            </li>
          ))}
        </ul>
      )}

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

      {items && items.last_page > 1 && (
        <p>
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            ←
          </button>{' '}
          {items.current_page} / {items.last_page}{' '}
          <button type="button" disabled={page >= items.last_page} onClick={() => setPage((p) => p + 1)}>
            →
          </button>
        </p>
      )}
    </div>
  )
}
