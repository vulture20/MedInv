import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { describeError } from '../admin/adminErrors'
import { SortableHeader } from '../../components/SortableHeader'
import { MediaItemDetailDialog } from '../libraries/MediaItemDetailDialog'
import { formatPrice, subtitleField, type LibraryRef, type MediaItem } from '../libraries/mediaItemFields'
import { SearchFilterPanel, type SearchFilterOptions } from './SearchFilterPanel'
import { SavedSearches, type SavedSearch } from './SavedSearches'
import {
  EMPTY_FILTERS,
  filtersFromParams,
  filtersToRequestParams,
  filtersToSearchParamsInit,
  hasAnyCriteria,
  paramsObjectToSearchParamsInit,
  type SearchFiltersState,
  type SortColumn,
} from './searchFilters'

/** GET /search's response shape: a full media item (SearchService returns the whole Eloquent model, no field selection) plus its owning library — unlike LibraryDetailPage's item list, results can span several libraries/media types at once, so each hit carries its own `library` rather than the page having one fixed library for all of them. */
interface SearchHit extends MediaItem {
  library: LibraryRef
}

/** GitHub issue #122's auto-scroll-to-results respects `prefers-reduced-motion`, same care index.css's own CSS-only checks (e.g. DashboardPage.tsx's carousel animation) already take — `scrollIntoView`'s `behavior` has no CSS equivalent, so this is a JS-level check instead, same `window.matchMedia` primitive ThemeContext.tsx already uses for its own (unrelated) dark-mode detection. */
function prefersReducedMotion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * Search results (briefing 13.), grown by GitHub issue #73 from a plain
 * free-text-plus-fuzzy-toggle page into a real search mask. Reachable
 * either via the header search box (Header.tsx, which navigates here with
 * `?query=`) or by searching again directly on this page.
 *
 * Every filter (query, media type/library scoping, field-specific search
 * scope, attribute filters, range filters, fuzzy) lives in one flat
 * `SearchFiltersState` (searchFilters.ts) with two copies: `draft` (edited
 * freely by SearchFilterPanel/the query input, not yet applied) and
 * whatever's currently in the URL (`params`, read via filtersFromParams())
 * — the actually-applied state a request was last made for. Submitting the
 * form commits `draft` into the URL in one shot (filtersToSearchParamsInit()),
 * same "type first, apply on submit" shape the query input alone used to
 * have, just generalized to the whole filter mask so results stay
 * bookmarkable/shareable per the issue's own "technical implications" note.
 *
 * GitHub issue #100: results render as the same sortable table
 * LibraryDetailPage uses for a single library's items (cover, title, EAN,
 * location — plus a `library` column here, since a mixed result set needs
 * it, and minus the per-media-type subtitle/CD-only columns
 * LibraryDetailPage has, which only make sense for a single, known media
 * type). Sorting is client-side (SortableHeader is agnostic to that, see
 * its own docblock) — GET /search returns every match in one unpaginated
 * response already, so there's no server round trip to sort via, unlike
 * LibraryDetailPage's sort_by/sort_dir. GitHub issue #73's "nice to have"
 * sort dimensions beyond the four visible columns (release date/price/
 * added date) are offered via a standalone <select> instead of extra table
 * columns, sharing the exact same sortBy/sortDir state SortableHeader's
 * column clicks already write to. Clicking a result opens
 * MediaItemDetailDialog right here instead of navigating to its owning
 * library, so browsing through several hits no longer needs a fresh search
 * after every one.
 */
export function SearchPage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const appliedFilters = useMemo(() => filtersFromParams(params), [params])
  const [draft, setDraft] = useState<SearchFiltersState>(appliedFilters)
  const [results, setResults] = useState<SearchHit[]>([])
  const [error, setError] = useState<string | null>(null)
  const [sortBy, setSortBy] = useState<SortColumn | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')

  const [filterOptions, setFilterOptions] = useState<SearchFilterOptions | null>(null)
  const [savedSearches, setSavedSearches] = useState<SavedSearch[]>([])
  const [savedSearchError, setSavedSearchError] = useState<string | null>(null)

  // GitHub issue #122 — the filter panel above the results table (#73)
  // pushed results far enough down that submitting a search left the page
  // at its old scroll position, with nothing visibly different above the
  // fold. `searchCompletedAt` is bumped only when a search actually
  // finishes (see the fetch effect below) and consumed by its own
  // useEffect further down, which scrolls resultsRef into view once the
  // results <section> has actually re-rendered.
  const resultsRef = useRef<HTMLElement>(null)
  const [searchCompletedAt, setSearchCompletedAt] = useState(0)

  // Every library visible to this user (GET /libraries) — both the filter
  // panel's own library <select> and the detail dialog's "move to another
  // library" target list, same as LibraryDetailPage. Fetched once up
  // front rather than per search, since it doesn't depend on the search
  // itself.
  const [libraries, setLibraries] = useState<LibraryRef[]>([])
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [selectedLibrary, setSelectedLibrary] = useState<LibraryRef | null>(null)

  useEffect(() => {
    apiClient
      .get<LibraryRef[]>('/libraries')
      .then(({ data }) => setLibraries(data))
      .catch((err) => {
        // GitHub issue #109 — previously missing entirely, same gap already
        // fixed on StatisticsPage.tsx/ReportDetailPage.tsx/LibraryDetailPage.tsx:
        // a failed request just left `libraries` at its initial `[]` with no
        // indication anything went wrong — here, that silently empties the
        // detail dialog's "move to another library" dropdown and the filter
        // panel's own library filter, instead of the whole page failing
        // outright, so a distinct message from search.error (which is about
        // the search itself, not this) matters.
        console.error('Failed to load libraries:', err)
        setError(t('search.librariesError'))
      })
  }, [t])

  // GitHub issue #73 — populates SearchFilterPanel's attribute filter <select>s.
  useEffect(() => {
    apiClient
      .get<SearchFilterOptions>('/search/filter-options')
      .then(({ data }) => setFilterOptions(data))
      .catch((err) => console.error('Failed to load search filter options:', err))
  }, [])

  function loadSavedSearches() {
    apiClient
      .get<SavedSearch[]>('/saved-searches')
      .then(({ data }) => setSavedSearches(data))
      .catch((err) => {
        console.error('Failed to load saved searches:', err)
        setSavedSearchError(describeError(err, t))
      })
  }

  useEffect(() => {
    loadSavedSearches()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Keeps the draft in sync whenever the applied (URL) filters change from
  // elsewhere — a new search from the header box, browser back/forward, or
  // SavedSearches.tsx's "apply" button — so the form always shows what's
  // actually been searched for, not stale edits from before that change.
  useEffect(() => {
    setDraft(appliedFilters)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  // GitHub issue #109's original reasoning, generalized from `query` alone
  // to the whole applied filter set (GitHub issue #73): a genuinely new
  // search shouldn't keep whatever sort order was picked for a previous,
  // unrelated result set.
  useEffect(() => {
    setSortBy(null)
    setSortDir('asc')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  useEffect(() => {
    if (!hasAnyCriteria(appliedFilters)) {
      setResults([])
      return
    }
    setError(null)
    apiClient
      .get<SearchHit[]>('/search', { params: filtersToRequestParams(appliedFilters) })
      .then(({ data }) => {
        setResults(data)
        // GitHub issue #122 — a dedicated counter rather than scrolling
        // right here or from a plain `useEffect(..., [results])`: `results`
        // also changes from MediaItemDetailDialog's onUpdated/onDeleted/
        // onMoved handlers below (editing/removing/moving an item while
        // browsing results), which must NOT re-trigger a scroll — only an
        // actual completed search should.
        setSearchCompletedAt(Date.now())
      })
      .catch((err) => {
        // Previously missing entirely — any failed request (a validation
        // error, a session hiccup, ...) left `results` at its initial `[]`
        // with no indication anything went wrong, indistinguishable from a
        // real "no matches" result.
        console.error('Search failed:', err)
        setResults([])
        setError(t('search.error'))
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appliedFilters, t])

  // GitHub issue #122 — runs after the results <section> above has
  // actually re-rendered with the new data (a plain effect, not the fetch
  // callback itself, guarantees resultsRef.current is up to date). The
  // `searchCompletedAt === 0` guard only excludes the component's very
  // first render (before any fetch has ever completed) — a search that's
  // already in the URL on mount (e.g. via the header search box, or a
  // bookmarked/shared search URL) still scrolls once it resolves, same as
  // clicking "Suchen" directly on this page would.
  useEffect(() => {
    if (searchCompletedAt === 0) return
    resultsRef.current?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' })
  }, [searchCompletedAt])

  function submitSearch(e: React.FormEvent) {
    e.preventDefault()
    setParams(filtersToSearchParamsInit(draft))
  }

  function applyParams(filters: Record<string, string | string[]>) {
    setParams(paramsObjectToSearchParamsInit(filters))
  }

  function resetFilters() {
    setDraft(EMPTY_FILTERS)
    setParams([])
  }

  function saveCurrentSearch(name: string) {
    apiClient
      .post<SavedSearch>('/saved-searches', { name, filters: filtersToRequestParams(appliedFilters) })
      .then(() => loadSavedSearches())
      .catch((err) => {
        console.error('Failed to save the search:', err)
        setSavedSearchError(t('search.savedSearches.saveError'))
      })
  }

  function deleteSavedSearch(id: number) {
    apiClient
      .delete(`/saved-searches/${id}`)
      .then(() => setSavedSearches((prev) => prev.filter((s) => s.id !== id)))
      .catch((err) => {
        console.error('Failed to delete the saved search:', err)
        setSavedSearchError(describeError(err, t))
      })
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
    return [...results].sort((a, b) => String(valueOf(a)).localeCompare(String(valueOf(b)), undefined, { numeric: true }) * dir)
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

  const hasResults = hasAnyCriteria(appliedFilters)

  return (
    <div className="panel-page panel-page--wide">
      <header className="panel-page__header">
        <h1>{appliedFilters.query ? t('search.resultsFor', { query: appliedFilters.query }) : t('nav.search')}</h1>
      </header>

      <form onSubmit={submitSearch} role="search">
        <input
          type="search"
          value={draft.query}
          onChange={(e) => setDraft((prev) => ({ ...prev, query: e.target.value }))}
          placeholder={t('search.placeholder')}
          aria-label={t('search.placeholder')}
          autoFocus
        />
        <button type="submit">{t('search.submit')}</button>
        <button type="button" onClick={resetFilters}>
          {t('search.filters.reset')}
        </button>

        <SearchFilterPanel draft={draft} onChange={(patch) => setDraft((prev) => ({ ...prev, ...patch }))} filterOptions={filterOptions} libraries={libraries} />
      </form>

      <SavedSearches savedSearches={savedSearches} error={savedSearchError} onApply={applyParams} onSave={saveCurrentSearch} onDelete={deleteSavedSearch} />

      {error && <p role="alert">{error}</p>}

      {hasResults && (
        <section className="panel-card" ref={resultsRef}>
          {/* GitHub issue #121, GitHub issue #127 — left, above the results
              count (same .library-items-toolbar wrapper shape
              LibraryDetailPage.tsx's own export button sits in, just above
              instead of below the count here per explicit request). A plain
              GET navigation (same window.location.href pattern
              LibraryDetailPage.tsx/ReportDetailPage.tsx's own PDF export
              buttons already use, so the browser's normal
              Content-Disposition handling and already-authenticated session
              cookie do the rest), built with the exact same filter params as
              the results just fetched above — the export always matches
              what's currently on screen, not a second, separately-tracked
              "last search". apiClient.getUri() (not the
              setSearchParams-oriented filtersToSearchParamsInit()) since
              this needs the bracket-style array serialization
              (`media_types[]=...`) the backend's query-string parsing
              actually expects, the same shape apiClient's own GET /search
              request already sends. sort_by/sort_dir ride along too (GitHub
              issue #127) so the exported row order matches whatever's
              currently on screen, whether set via the "Sortieren nach"
              <select> below or by clicking a column header. */}
          <div className="search-results__toolbar">
            <button
              type="button"
              onClick={() => {
                window.location.href = apiClient.getUri({
                  url: '/search/export/pdf',
                  params: { ...filtersToRequestParams(appliedFilters), ...(sortBy ? { sort_by: sortBy, sort_dir: sortDir } : {}) },
                })
              }}
            >
              {t('reports.exportPdf')}
            </button>
          </div>
          <div className="search-results__header">
            {/* GitHub issue #109 — mirrors LibraryDetailPage's <h2>{itemsTitle}</h2>, shown even at 0 results (still followed by the noResults hint below), so a search never leaves the hit count to be counted by eye. */}
            <h2>{t('search.resultsTitle', { count: sortedResults.length })}</h2>
            {/* GitHub issue #73's "nice to have": sorting by dimensions the table doesn't show a column for (release date/price/added date), alongside the four SortableHeader columns below — both write to the same sortBy/sortDir state. */}
            <label className="search-results__sort">
              {t('search.filters.sortBy')}
              <select value={sortBy ?? ''} onChange={(e) => (e.target.value ? handleSort(e.target.value) : setSortBy(null))}>
                <option value="">{t('search.filters.sortDefault')}</option>
                <option value="title">{t('mediaItem.fields.title')}</option>
                <option value="release_date">{t('mediaItem.fields.release_date')}</option>
                <option value="price">{t('mediaItem.fields.price')}</option>
                <option value="created_at">{t('reports.recentAdditions.addedAt')}</option>
              </select>
            </label>
          </div>
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
                  {/* location (GitHub issue #96) applies to every media type, so — unlike the per-media-type subtitle field above — it doesn't need special handling for a mixed result set. GitHub issue #109. */}
                  <SortableHeader column="location" label={t('mediaItem.fields.location')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
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
                      {/* Price/release date (GitHub issue #73's sort dimensions) aren't table columns of their own — shown inline so the value behind a non-default sort is still visible without opening every row. */}
                      {(sortBy === 'price' || sortBy === 'release_date') && (
                        <span className="hint">
                          {' '}
                          — {sortBy === 'price' ? formatPrice(hit.price ?? null, hit.currency ?? null, i18n.language) : hit.release_date?.slice(0, 10) || '—'}
                        </span>
                      )}
                    </td>
                    <td>{hit.ean}</td>
                    <td>
                      <span className="media-type-badge">{t(`libraries.mediaType.${hit.library.media_type}`)}</span> {hit.library.name}
                    </td>
                    <td>{hit.location ?? ''}</td>
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
