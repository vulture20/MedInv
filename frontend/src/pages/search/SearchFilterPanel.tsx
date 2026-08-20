import { useTranslation } from 'react-i18next'
import type { LibraryRef, MediaType } from '../libraries/mediaItemFields'
import type { SearchField, SearchFiltersState } from './searchFilters'

/** GET /search/filter-options's response shape — see SearchService::filterOptionsFor()'s own docblock. */
export interface SearchFilterOptions {
  book: { genre: string[]; format: string[]; language: string[] }
  cd: { medium: string[] }
  dvd_bluray: { medium: string[]; languages: string[] }
}

const MEDIA_TYPES: MediaType[] = ['book', 'cd', 'dvd_bluray']
const SEARCH_FIELDS: SearchField[] = ['all', 'title', 'creator', 'description', 'identifier', 'location', 'tracks']

/** A pair of min/max number inputs (GitHub issue #73's range filters: price, year, page count, disc count, runtime) — the one shape every range filter below shares. */
function RangeFilter({
  label,
  min,
  max,
  step,
  onMinChange,
  onMaxChange,
}: {
  label: string
  min: string
  max: string
  step?: string
  onMinChange: (value: string) => void
  onMaxChange: (value: string) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="search-filters__range">
      <span className="search-filters__range-label">{label}</span>
      <input
        type="number"
        step={step ?? '1'}
        min="0"
        placeholder={t('search.filters.min')}
        aria-label={`${label} (${t('search.filters.min')})`}
        value={min}
        onChange={(e) => onMinChange(e.target.value)}
      />
      <span aria-hidden="true">–</span>
      <input
        type="number"
        step={step ?? '1'}
        min="0"
        placeholder={t('search.filters.max')}
        aria-label={`${label} (${t('search.filters.max')})`}
        value={max}
        onChange={(e) => onMaxChange(e.target.value)}
      />
    </div>
  )
}

/** A `<select multiple>` bound to one of SearchFiltersState's string[] fields — genre/format/language/medium/languages all share this exact shape, only the label/options/current value differ. Rendered only when at least one option actually exists (GitHub issue #73's own "values that actually occur" filter-options endpoint can come back empty, e.g. no book in any visible library has a genre set). */
function MultiSelect({ label, options, value, onChange }: { label: string; options: string[]; value: string[]; onChange: (value: string[]) => void }) {
  if (options.length === 0) return null

  return (
    <label className="search-filters__multiselect">
      {label}
      <select multiple value={value} onChange={(e) => onChange(Array.from(e.target.selectedOptions, (o) => o.value))} size={Math.min(options.length, 5)}>
        {options.map((option) => (
          <option key={option} value={option}>
            {option}
          </option>
        ))}
      </select>
    </label>
  )
}

/**
 * GitHub issue #73 — the search mask's actual filter controls, split out
 * of SearchPage.tsx since the full set (media type/library scoping, a
 * field-specific search scope, attribute filters, five range filters) is
 * large enough to make that file hard to read otherwise. Every control
 * writes into the same flat `draft` (SearchPage.tsx's local, uncommitted
 * copy of the filter state) via `onChange` — nothing here talks to the URL
 * or fires a request itself; SearchPage.tsx's surrounding <form> submit is
 * what applies the whole draft at once, same as the query input already
 * worked before this issue.
 */
export function SearchFilterPanel({
  draft,
  onChange,
  filterOptions,
  libraries,
}: {
  draft: SearchFiltersState
  onChange: (patch: Partial<SearchFiltersState>) => void
  filterOptions: SearchFilterOptions | null
  libraries: LibraryRef[]
}) {
  const { t } = useTranslation()

  // `medium` (GitHub issue #73) applies to CD and DVD-Blu-ray alike — one
  // combined <select> sourced from both media types' distinct values,
  // rather than two separate ones for what's the exact same filter param.
  const mediumOptions = Array.from(new Set([...(filterOptions?.cd.medium ?? []), ...(filterOptions?.dvd_bluray.medium ?? [])])).sort()

  /**
   * GitHub issue #123 — every attribute filter here only ever applies to a
   * subset of media types (mediaItem.fields.* labels are shared with
   * FIELD_SPECS' per-media-type edit form, where that's unambiguous since
   * only the relevant fields for one media type show at once), but this
   * panel shows all of them side by side regardless of which media types
   * are selected — most visibly, `language` (book) and `languages`
   * (DVD-Blu-ray) end up reading as two near-identical "Sprache"/
   * "Sprache(n)" fields with nothing to tell them apart. Appends which
   * media type(s) each one actually filters, reusing the existing
   * libraries.mediaType.* labels rather than adding new translation keys
   * for what both already say on their own.
   */
  function labelWithMediaTypes(field: string, mediaTypes: MediaType[]): string {
    return `${t(field)} (${mediaTypes.map((type) => t(`libraries.mediaType.${type}`)).join(', ')})`
  }

  return (
    <section className="panel-card search-filters">
      <h2>{t('search.filters.heading')}</h2>

      <fieldset className="search-filters__row">
        <legend>{t('libraries.mediaTypeLabel')}</legend>
        {MEDIA_TYPES.map((type) => (
          <label key={type} className="search-filters__checkbox">
            <input
              type="checkbox"
              checked={draft.mediaTypes.includes(type)}
              onChange={(e) =>
                onChange({ mediaTypes: e.target.checked ? [...draft.mediaTypes, type] : draft.mediaTypes.filter((mediaType) => mediaType !== type) })
              }
            />
            {t(`libraries.mediaType.${type}`)}
          </label>
        ))}
      </fieldset>

      {libraries.length > 0 && (
        <label className="search-filters__multiselect">
          {t('libraries.title')}
          <select
            multiple
            value={draft.libraryIds.map(String)}
            onChange={(e) => onChange({ libraryIds: Array.from(e.target.selectedOptions, (o) => Number(o.value)) })}
            size={Math.min(libraries.length, 5)}
          >
            {libraries.map((library) => (
              <option key={library.id} value={library.id}>
                {library.name} ({t(`libraries.mediaType.${library.media_type}`)})
              </option>
            ))}
          </select>
        </label>
      )}

      <label>
        {t('search.filters.fieldLabel')}
        <select value={draft.field} onChange={(e) => onChange({ field: e.target.value as SearchField })}>
          {SEARCH_FIELDS.map((field) => (
            <option key={field} value={field}>
              {field === 'all' && t('search.filters.field.all')}
              {field === 'title' && t('mediaItem.fields.title')}
              {field === 'creator' && t('search.filters.field.creator')}
              {field === 'description' && t('mediaItem.fields.description')}
              {field === 'identifier' && t('search.filters.field.identifier')}
              {field === 'location' && t('mediaItem.fields.location')}
              {field === 'tracks' && t('mediaItem.tracklist')}
            </option>
          ))}
        </select>
      </label>

      <div className="search-filters__row">
        <MultiSelect
          label={labelWithMediaTypes('mediaItem.fields.genre', ['book'])}
          options={filterOptions?.book.genre ?? []}
          value={draft.genre}
          onChange={(genre) => onChange({ genre })}
        />
        <MultiSelect
          label={labelWithMediaTypes('mediaItem.fields.format', ['book'])}
          options={filterOptions?.book.format ?? []}
          value={draft.format}
          onChange={(format) => onChange({ format })}
        />
        <MultiSelect
          label={labelWithMediaTypes('mediaItem.fields.language', ['book'])}
          options={filterOptions?.book.language ?? []}
          value={draft.language}
          onChange={(language) => onChange({ language })}
        />
        <MultiSelect
          label={labelWithMediaTypes('mediaItem.fields.medium', ['cd', 'dvd_bluray'])}
          options={mediumOptions}
          value={draft.medium}
          onChange={(medium) => onChange({ medium })}
        />
        <MultiSelect
          label={labelWithMediaTypes('mediaItem.fields.languages', ['dvd_bluray'])}
          options={filterOptions?.dvd_bluray.languages ?? []}
          value={draft.languages}
          onChange={(languages) => onChange({ languages })}
        />
      </div>

      <div className="search-filters__row">
        <RangeFilter
          label={t('mediaItem.fields.price')}
          step="0.01"
          min={draft.priceMin}
          max={draft.priceMax}
          onMinChange={(priceMin) => onChange({ priceMin })}
          onMaxChange={(priceMax) => onChange({ priceMax })}
        />
        <RangeFilter
          label={t('search.filters.year')}
          min={draft.yearMin}
          max={draft.yearMax}
          onMinChange={(yearMin) => onChange({ yearMin })}
          onMaxChange={(yearMax) => onChange({ yearMax })}
        />
        <RangeFilter
          label={t('mediaItem.fields.page_count')}
          min={draft.pageCountMin}
          max={draft.pageCountMax}
          onMinChange={(pageCountMin) => onChange({ pageCountMin })}
          onMaxChange={(pageCountMax) => onChange({ pageCountMax })}
        />
        <RangeFilter
          label={t('mediaItem.fields.disc_count')}
          min={draft.discCountMin}
          max={draft.discCountMax}
          onMinChange={(discCountMin) => onChange({ discCountMin })}
          onMaxChange={(discCountMax) => onChange({ discCountMax })}
        />
        <RangeFilter
          label={t('mediaItem.runtime')}
          min={draft.runtimeMin}
          max={draft.runtimeMax}
          onMinChange={(runtimeMin) => onChange({ runtimeMin })}
          onMaxChange={(runtimeMax) => onChange({ runtimeMax })}
        />
      </div>

      <label className="search-filters__checkbox">
        <input type="checkbox" checked={draft.fuzzy} onChange={(e) => onChange({ fuzzy: e.target.checked })} />
        {t('search.fuzzy')}
      </label>
    </section>
  )
}
