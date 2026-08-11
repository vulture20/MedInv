import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'

interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
}

/** Media item fields vary by media_type (briefing 6.) — only the ones shown here are read. */
interface MediaItem {
  id: number
  title: string
  ean: string
  authors?: string | null
  artist?: string | null
  director?: string | null
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
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    void (async () => {
      setLoading(true)
      const [libraryRes, itemsRes] = await Promise.all([
        apiClient.get<Library>(`/libraries/${id}`),
        apiClient.get<Paginated<MediaItem>>(`/libraries/${id}/items`, { params: { page } }),
      ])
      setLibrary(libraryRes.data)
      setItems(itemsRes.data)
      setLoading(false)
    })()
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
              <strong>{item.title}</strong>
              {subtitle(item, library.media_type) && <> — {subtitle(item, library.media_type)}</>}
              {' — '}
              {item.ean}
            </li>
          ))}
        </ul>
      )}

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
