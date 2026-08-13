import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { MediaItemDetailDialog, type MediaItem } from './MediaItemDetailDialog'

interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
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
  const { id } = useParams<{ id: string }>()
  const [library, setLibrary] = useState<Library | null>(null)
  const [items, setItems] = useState<Paginated<MediaItem> | null>(null)
  const [libraries, setLibraries] = useState<Library[]>([])
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)

  async function load() {
    setLoading(true)
    const [libraryRes, itemsRes, librariesRes] = await Promise.all([
      apiClient.get<Library>(`/libraries/${id}`),
      apiClient.get<Paginated<MediaItem>>(`/libraries/${id}/items`, { params: { page } }),
      // Needed for the detail dialog's "move to another library" target list
      // (only libraries visible to this user are returned to begin with).
      apiClient.get<Library[]>('/libraries'),
    ])
    setLibrary(libraryRes.data)
    setItems(itemsRes.data)
    setLibraries(librariesRes.data)
    setLoading(false)
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, page])

  if (loading || !library) return <p>…</p>

  return (
    <div>
      <p>
        <Link to="/libraries">{t('libraries.title')}</Link>
      </p>
      <h1>{library.name}</h1>
      <p>
        {t(`libraries.mediaType.${library.media_type}`)} — {library.owner.name}
      </p>
      {library.description && <p>{library.description}</p>}

      {items && items.data.length === 0 ? (
        <p>{t('libraries.noItems')}</p>
      ) : (
        <ul className="media-item-list">
          {items?.data.map((item) => (
            <li key={item.id}>
              {/* Opens MediaItemDetailDialog (view/edit/delete/move) below. */}
              <button type="button" className="media-item-list__row" onClick={() => setSelectedItem(item)}>
                {/* Served through the API (MediaItemController::cover()), not a direct
                    storage URL — see CoverDownloadService's docblock for why — so it
                    needs the session cookie even cross-origin in local dev. */}
                {item.cover_path && (
                  <img
                    className="media-item-list__cover"
                    src={`${apiClient.defaults.baseURL}/libraries/${library.id}/items/${item.id}/cover`}
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
        onClose={() => setSelectedItem(null)}
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
