import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { SortableHeader } from '../../components/SortableHeader'
import { MediaItemDetailDialog } from '../libraries/MediaItemDetailDialog'
import { subtitleField, type LibraryRef, type MediaItem } from '../libraries/mediaItemFields'

/** GET /search's response shape: a full media item (SearchService returns the whole Eloquent model, no field selection) plus its owning library — unlike LibraryDetailPage's item list, results can span several libraries/media types at once, so each hit carries its own `library` rather than the page having one fixed library for all of them. */
interface SearchHit extends MediaItem {
  library: LibraryRef
}

type SortColumn = 'title' | 'ean' | 'library'

/**
 * Search results (briefing 13.). Reachable either via the header search box
 * (Header.tsx, which navigates here with `?query=`) or by searching again
 * directly on this page — the input+button here update the same `query` URL
 * param via setSearchParams rather than keeping separate local state, so
 * both entry points stay in sync and results stay bookmarkable/shareable.
 *
 * GitHub issue #100: results render as the same sortable table
 * LibraryDetailPage uses for a single library's items (cover, title, EAN —
 * plus a `library` column here, since a mixed result set needs it, and
 * minus the per-media-type subtitle/CD-only columns LibraryDetailPage has,
 * which only make sense for a single, known media type). Sorting is
 * client-side (SortableHeader is agnostic to that, see its own docblock) —
 * GET /search returns every match in one unpaginated response already, so
 * there's no server round trip to sort via, unlike LibraryDetailPage's
 * sort_by/sort_dir. Clicking a result opens MediaItemDetailDialog right
 * here instead of navigating to its owning library, so browsing through
 * several hits no longer needs a fresh search after every one.
 */
export function SearchPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const query = params.get('query') ?? ''
  const [queryInput, setQueryInput] = useState(query)
  const [fuzzy, setFuzzy] = useState(false)
  const [results, setResults] = useState<SearchHit[]>([])
  const [error, setError] = useState<string | null>(null)
  const [sortBy, setSortBy] = useState<SortColumn | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')

  // Every library visible to this user (GET /libraries) — the detail
  // dialog's "move to another library" target list, same as
  // LibraryDetailPage. Fetched once up front rather than per result, since
  // it doesn't depend on the search itself.
  const [libraries, setLibraries] = useState<LibraryRef[]>([])
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [selectedLibrary, setSelectedLibrary] = useState<LibraryRef | null>(null)

  useEffect(() => {
    apiClient.get<LibraryRef[]>('/libraries').then(({ data }) => setLibraries(data))
  }, [])

  // Keeps the input field in sync when `query` changes from elsewhere (e.g. a
  // new search from the header box while already on this page).
  useEffect(() => {
    setQueryInput(query)
  }, [query])

  useEffect(() => {
    if (!query) {
      setResults([])
      return
    }
    setError(null)
    apiClient
      .get<SearchHit[]>('/search', { params: { query, fuzzy } })
      .then(({ data }) => setResults(data))
      .catch((err) => {
        // Previously missing entirely — any failed request (a validation
        // error, a session hiccup, ...) left `results` at its initial `[]`
        // with no indication anything went wrong, indistinguishable from a
        // real "no matches" result.
        console.error('Search failed:', err)
        setResults([])
        setError(t('search.error'))
      })
  }, [query, fuzzy, t])

  function submitSearch(e: React.FormEvent) {
    e.preventDefault()
    if (queryInput.trim()) setParams({ query: queryInput.trim() })
  }

  function handleSort(column: string) {
    if (sortBy === column) {
      setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column as SortColumn)
      setSortDir('asc')
    }
  }

  const sortedResults = useMemo(() => {
    if (!sortBy) return results
    const dir = sortDir === 'asc' ? 1 : -1
    const valueOf = (hit: SearchHit) => (sortBy === 'library' ? hit.library.name : hit[sortBy]) ?? ''
    return [...results].sort((a, b) => String(valueOf(a)).localeCompare(String(valueOf(b))) * dir)
  }, [results, sortBy, sortDir])

  function activateRow(hit: SearchHit) {
    setSelectedItem(hit)
    setSelectedLibrary(hit.library)
  }

  function closeDialog() {
    setSelectedItem(null)
    setSelectedLibrary(null)
  }

  /** A hit no longer belongs where this search found it (deleted, or moved to a different library) — drop it from the results list rather than re-running the search. */
  function removeSelectedFromResults() {
    setResults((prev) => prev.filter((hit) => !(hit.id === selectedItem?.id && hit.library.id === selectedLibrary?.id)))
    closeDialog()
  }

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{query ? t('search.resultsFor', { query }) : t('search.placeholder')}</h1>
      </header>

      <form onSubmit={submitSearch} role="search">
        <input
          type="search"
          value={queryInput}
          onChange={(e) => setQueryInput(e.target.value)}
          placeholder={t('search.placeholder')}
          aria-label={t('search.placeholder')}
          autoFocus
        />
        <button type="submit">{t('search.submit')}</button>
      </form>

      <label>
        <input type="checkbox" checked={fuzzy} onChange={(e) => setFuzzy(e.target.checked)} />
        {t('search.fuzzy')}
      </label>

      {error && <p role="alert">{error}</p>}

      {query && (
        <section className="panel-card">
          {results.length === 0 ? (
            <p className="hint">{t('search.noResults')}</p>
          ) : (
            <table className="media-item-table">
              <thead>
                <tr>
                  <th aria-hidden="true" />
                  <SortableHeader column="title" label={t('mediaItem.fields.title')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                  <SortableHeader column="ean" label={t('mediaItem.fields.ean')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                  <SortableHeader column="library" label={t('search.library')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                </tr>
              </thead>
              <tbody>
                {sortedResults.map((hit) => (
                  <tr key={`${hit.library.id}-${hit.id}`} className="media-item-table__row" onClick={() => activateRow(hit)}>
                    <td>
                      {/* Same small generated thumbnail LibraryDetailPage's own table uses (MediaItemController::coverThumbnail()), not the full cover. */}
                      {hit.cover_path && (
                        <img
                          className="media-item-table__cover"
                          src={`${apiClient.defaults.baseURL}/libraries/${hit.library.id}/items/${hit.id}/cover/thumbnail`}
                          crossOrigin="use-credentials"
                          alt=""
                        />
                      )}
                    </td>
                    <td>
                      <button type="button" className="media-item-table__title-button" onClick={(e) => { e.stopPropagation(); activateRow(hit) }}>
                        {hit.title}
                      </button>
                      {/* The media-type-specific field LibraryDetailPage shows as its own sortable column — shown inline here instead of as a header of its own, since its meaning (author/artist/director) changes per row in a mixed result set. */}
                      {hit[subtitleField(hit.library.media_type)] && (
                        <span className="hint"> — {hit[subtitleField(hit.library.media_type)]}</span>
                      )}
                    </td>
                    <td>{hit.ean}</td>
                    <td>
                      <span className="media-type-badge">{t(`libraries.mediaType.${hit.library.media_type}`)}</span> {hit.library.name}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      )}

      <MediaItemDetailDialog
        library={selectedLibrary ?? { id: 0, name: '', media_type: 'book', owner: { id: 0, name: '' } }}
        item={selectedItem}
        libraries={libraries}
        onClose={closeDialog}
        onUpdated={(updated) => {
          setResults((prev) => prev.map((hit) => (hit.id === updated.id && hit.library.id === selectedLibrary?.id ? { ...hit, ...updated } : hit)))
          setSelectedItem(updated)
        }}
        onDeleted={removeSelectedFromResults}
        onMoved={removeSelectedFromResults}
      />
    </div>
  )
}
