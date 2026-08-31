import type { MediaType } from '../libraries/mediaItemFields'

/** `field` param — which column(s) a `query` matches against, GitHub issue #73's field-specific search scope. Mirrors SearchService::FIELD_GROUPS/columnsFor() on the backend. */
export type SearchField = 'all' | 'title' | 'creator' | 'description' | 'identifier' | 'location' | 'tracks'

/** `sortBy` — every dimension SortableHeader's table columns (title/ean/library/location) plus GitHub issue #73's "nice to have" sort dimensions (release date/price/added date) offer via the standalone sort <select>, see SearchPage.tsx. */
export type SortColumn = 'title' | 'ean' | 'library' | 'location' | 'release_date' | 'price' | 'created_at'

/**
 * The whole search-mask state (GitHub issue #73) — one flat object rather
 * than the scattered useState()s SearchPage.tsx used to have, since every
 * one of these fields needs the exact same treatment: read from the URL on
 * load (so a search stays bookmarkable/shareable, per the issue's own
 * "technical implications" note), edited freely as a local draft, and
 * applied to both the URL and the actual GET /search request in one shot
 * when the user submits. Range/number fields are kept as strings (an
 * <input type="number">'s own value type) rather than `number | null` —
 * `''` is "not set", converted to an actual number only where it's
 * consumed (filtersToRequestParams()).
 */
export interface SearchFiltersState {
  query: string
  fuzzy: boolean
  /** GitHub issue #209 — matches an item with has_duplicates=true or duplicate_count>0 (GitHub issue #208's fields). */
  duplicates: boolean
  field: SearchField
  mediaTypes: MediaType[]
  libraryIds: number[]
  genre: string[]
  format: string[]
  language: string[]
  medium: string[]
  languages: string[]
  priceMin: string
  priceMax: string
  yearMin: string
  yearMax: string
  pageCountMin: string
  pageCountMax: string
  discCountMin: string
  discCountMax: string
  runtimeMin: string
  runtimeMax: string
}

export const EMPTY_FILTERS: SearchFiltersState = {
  query: '',
  fuzzy: false,
  duplicates: false,
  field: 'all',
  mediaTypes: [],
  libraryIds: [],
  genre: [],
  format: [],
  language: [],
  medium: [],
  languages: [],
  priceMin: '',
  priceMax: '',
  yearMin: '',
  yearMax: '',
  pageCountMin: '',
  pageCountMax: '',
  discCountMin: '',
  discCountMax: '',
  runtimeMin: '',
  runtimeMax: '',
}

const MEDIA_TYPES: MediaType[] = ['book', 'cd', 'dvd_bluray']
const SEARCH_FIELDS: SearchField[] = ['all', 'title', 'creator', 'description', 'identifier', 'location', 'tracks']

export function filtersFromParams(params: URLSearchParams): SearchFiltersState {
  const field = params.get('field')

  return {
    query: params.get('query') ?? '',
    fuzzy: params.get('fuzzy') === 'true',
    duplicates: params.get('duplicates') === 'true',
    field: field && (SEARCH_FIELDS as string[]).includes(field) ? (field as SearchField) : 'all',
    mediaTypes: params.getAll('media_types').filter((v): v is MediaType => (MEDIA_TYPES as string[]).includes(v)),
    libraryIds: params
      .getAll('library_ids')
      .map(Number)
      .filter((n) => !Number.isNaN(n)),
    genre: params.getAll('genre'),
    format: params.getAll('format'),
    language: params.getAll('language'),
    medium: params.getAll('medium'),
    languages: params.getAll('languages'),
    priceMin: params.get('price_min') ?? '',
    priceMax: params.get('price_max') ?? '',
    yearMin: params.get('year_min') ?? '',
    yearMax: params.get('year_max') ?? '',
    pageCountMin: params.get('page_count_min') ?? '',
    pageCountMax: params.get('page_count_max') ?? '',
    discCountMin: params.get('disc_count_min') ?? '',
    discCountMax: params.get('disc_count_max') ?? '',
    runtimeMin: params.get('runtime_min') ?? '',
    runtimeMax: params.get('runtime_max') ?? '',
  }
}

/**
 * One flat `key -> value | value[]` object, the shared basis for both the
 * URL query string (setSearchParamsFromFilters()) and the actual GET
 * /search request params (SearchController's validated field names) — kept
 * as one function rather than two separately-maintained key lists that
 * could drift apart.
 */
function toParamsObject(filters: SearchFiltersState): Record<string, string | string[]> {
  const params: Record<string, string | string[]> = {}

  if (filters.query) params.query = filters.query
  if (filters.fuzzy) params.fuzzy = 'true'
  if (filters.duplicates) params.duplicates = 'true'
  if (filters.field !== 'all') params.field = filters.field
  if (filters.mediaTypes.length > 0) params.media_types = filters.mediaTypes
  if (filters.libraryIds.length > 0) params.library_ids = filters.libraryIds.map(String)
  if (filters.genre.length > 0) params.genre = filters.genre
  if (filters.format.length > 0) params.format = filters.format
  if (filters.language.length > 0) params.language = filters.language
  if (filters.medium.length > 0) params.medium = filters.medium
  if (filters.languages.length > 0) params.languages = filters.languages
  if (filters.priceMin) params.price_min = filters.priceMin
  if (filters.priceMax) params.price_max = filters.priceMax
  if (filters.yearMin) params.year_min = filters.yearMin
  if (filters.yearMax) params.year_max = filters.yearMax
  if (filters.pageCountMin) params.page_count_min = filters.pageCountMin
  if (filters.pageCountMax) params.page_count_max = filters.pageCountMax
  if (filters.discCountMin) params.disc_count_min = filters.discCountMin
  if (filters.discCountMax) params.disc_count_max = filters.discCountMax
  if (filters.runtimeMin) params.runtime_min = filters.runtimeMin
  if (filters.runtimeMax) params.runtime_max = filters.runtimeMax

  return params
}

/** The GET /search request params — same shape axios needs (array values become repeated `key[]=` params, see client.ts's own axios instance). */
export function filtersToRequestParams(filters: SearchFiltersState): Record<string, string | string[]> {
  return toParamsObject(filters)
}

/**
 * setSearchParams()'s own init shape doesn't support array-valued object
 * entries (only single strings) — this flattens a flat `key -> value |
 * value[]` object into repeated `[key, value]` tuples instead, which
 * URLSearchParams/React Router do support for multi-value params. Takes a
 * plain object rather than SearchFiltersState directly so it also works on
 * a SavedSearch's stored `filters` (SavedSearches.tsx, GitHub issue #73's
 * "nice to have") — the exact same flat shape toParamsObject() produces,
 * round-tripped through the backend's `filters` JSON column as-is.
 */
export function paramsObjectToSearchParamsInit(params: Record<string, string | string[]>): [string, string][] {
  const entries: [string, string][] = []
  for (const [key, value] of Object.entries(params)) {
    if (Array.isArray(value)) {
      for (const v of value) entries.push([key, v])
    } else {
      entries.push([key, value])
    }
  }
  return entries
}

/** SearchPage.tsx's own draft-to-URL commit — see paramsObjectToSearchParamsInit()'s docblock for why the flattening itself lives there. */
export function filtersToSearchParamsInit(filters: SearchFiltersState): [string, string][] {
  return paramsObjectToSearchParamsInit(toParamsObject(filters))
}

/** Whether this filter state would produce a request worth running at all — an entirely empty search mask (no query, nothing else set) intentionally shows nothing rather than dumping every visible item unprompted. */
export function hasAnyCriteria(filters: SearchFiltersState): boolean {
  return Object.keys(toParamsObject(filters)).length > 0
}
