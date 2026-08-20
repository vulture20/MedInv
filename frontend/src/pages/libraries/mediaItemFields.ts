export type MediaType = 'book' | 'cd' | 'dvd_bluray'

/** One row of a CD's track listing (GitHub issue #48) — matches the shape App\Domain\Metadata\TrackListRuntimeCalculator/the `tracks` JSON column expect. */
export interface Track {
  position: string | number | null
  title: string | null
  duration_seconds: number | null
}

export interface LibraryRef {
  id: number
  name: string
  media_type: MediaType
  owner: { id: number; name: string }
}

/** Union of every media_type's attributes (briefing 6.) — which ones actually apply depends on the owning library's media_type, see FIELD_SPECS. */
export interface MediaItem {
  id: number
  title: string
  ean: string
  cover_path?: string | null
  description?: string | null
  release_date?: string | null
  /** Eloquent's default timestamp, serialized on every response — GitHub issue #73's "added on" sort dimension (SearchPage.tsx) reads it directly rather than needing a dedicated summary field the way ReportsService::itemSummary()'s own `created_at` is. */
  created_at?: string
  price?: number | string | null
  /** ISO 4217 code (e.g. "USD"/"EUR") — a deliberate extension beyond briefing 6.1-6.3's fixed attribute set (GitHub issue #58), see the migration that added it for why. */
  currency?: string | null
  /** Free text, e.g. "Regal 3, Fach 2" — a second deliberate extension beyond briefing 6.1-6.3's fixed attribute set (GitHub issue #96), see the migration that added it for why. */
  location?: string | null
  // book
  authors?: string | null
  format?: string | null
  genre?: string | null
  page_count?: number | null
  language?: string | null
  publisher?: string | null
  isbn10?: string | null
  isbn13?: string | null
  // cd
  artist?: string | null
  asin?: string | null
  disc_count?: number | null
  /**
   * GitHub issue #48 — a deliberate extension beyond briefing 6.2's fixed
   * CD attribute set, not part of the generic FIELD_SPECS-driven edit form
   * (a track list isn't a single scalar value a plain text/number input
   * can represent) — MediaItemDetailDialog renders it separately, read-only.
   */
  tracks?: Track[] | null
  runtime_seconds?: number | null
  /** Whether `runtime_seconds` was summed from `tracks` rather than reported directly by a provider (GitHub issue #48) — shown as a "(computed)" hint next to the field. */
  runtime_computed?: boolean
  // dvd_bluray
  medium?: string | null
  runtime_minutes?: number | null
  languages?: string | null
  cast?: string | null
  director?: string | null
  production_year?: number | null
}

type FieldType = 'text' | 'textarea' | 'number' | 'date' | 'select'

export interface FieldSpec {
  key: keyof MediaItem
  type: FieldType
  required?: boolean
  /** Only meaningful for `type: 'select'` — the fixed list of values the `<select>` offers, e.g. CURRENCY_CODES. */
  options?: string[]
  /** Only meaningful for `type: 'select'` — how to label each `options` entry, e.g. currencyLabel(). Defaults to the raw value itself if omitted. */
  formatOption?: (value: string, language: string) => string
}

/**
 * ISO 4217 currency codes (GitHub issue #114 — a free-text `currency` field
 * was impractical to fill in correctly), sourced from the runtime's own
 * `Intl` data rather than a hand-maintained list, same reasoning and same
 * `Intl.supportedValuesOf` fallback pattern as SystemSettingsPage.tsx's own
 * `TIMEZONES` constant. Deliberately doesn't narrow what the backend
 * accepts (MediaItemController::rulesFor()'s `currency` rule stays a plain
 * `max:3` string, see its own comment) — a metadata provider or an import/
 * restore can still carry a code that isn't in this list, and the read/edit
 * views fall back to showing it verbatim rather than rejecting it; this
 * only shapes what a human manually typing a value gets offered.
 */
export const CURRENCY_CODES: string[] = (() => {
  try {
    return Intl.supportedValuesOf('currency')
  } catch {
    return ['EUR', 'USD', 'GBP', 'CHF', 'JPY']
  }
})()

/** "EUR — Euro" rather than a bare code, using the same locale the rest of the app already formats currency amounts in (see formatPrice() below) — falls back to the bare code if `Intl.DisplayNames` doesn't recognize it (or isn't available at all) for that language. */
export function currencyLabel(code: string, language: string): string {
  try {
    const name = new Intl.DisplayNames([language], { type: 'currency' }).of(code)
    return name && name !== code ? `${code} — ${name}` : code
  } catch {
    return code
  }
}

/**
 * Mirrors MediaItemController::rulesFor() field-for-field, minus `ean`:
 * MediaItemDetailDialog treats it as read-only (editing it would need the
 * same duplicate-EAN check creation goes through, see rulesFor()'s
 * docblock), while CreateMediaItemDialog asks for it separately since it's
 * required for a brand new item.
 */
export const FIELD_SPECS: Record<MediaType, FieldSpec[]> = {
  book: [
    { key: 'title', type: 'text', required: true },
    { key: 'authors', type: 'text' },
    { key: 'format', type: 'text' },
    { key: 'genre', type: 'text' },
    { key: 'page_count', type: 'number' },
    { key: 'language', type: 'text' },
    { key: 'publisher', type: 'text' },
    { key: 'release_date', type: 'date' },
    { key: 'price', type: 'number' },
    { key: 'currency', type: 'select', options: CURRENCY_CODES, formatOption: currencyLabel },
    { key: 'isbn10', type: 'text' },
    { key: 'isbn13', type: 'text' },
    { key: 'location', type: 'text' },
    { key: 'description', type: 'textarea' },
  ],
  cd: [
    { key: 'title', type: 'text', required: true },
    { key: 'artist', type: 'text' },
    { key: 'medium', type: 'text' },
    { key: 'asin', type: 'text' },
    { key: 'disc_count', type: 'number' },
    { key: 'runtime_seconds', type: 'number' },
    { key: 'release_date', type: 'date' },
    { key: 'price', type: 'number' },
    { key: 'currency', type: 'select', options: CURRENCY_CODES, formatOption: currencyLabel },
    { key: 'location', type: 'text' },
    { key: 'description', type: 'textarea' },
  ],
  dvd_bluray: [
    { key: 'title', type: 'text', required: true },
    { key: 'medium', type: 'text' },
    { key: 'disc_count', type: 'number' },
    { key: 'runtime_minutes', type: 'number' },
    { key: 'languages', type: 'text' },
    { key: 'cast', type: 'text' },
    { key: 'director', type: 'text' },
    { key: 'release_date', type: 'date' },
    { key: 'production_year', type: 'number' },
    { key: 'price', type: 'number' },
    { key: 'currency', type: 'select', options: CURRENCY_CODES, formatOption: currencyLabel },
    { key: 'location', type: 'text' },
    { key: 'description', type: 'textarea' },
  ],
}

/**
 * The item table's second column, media-type dependent (briefing 6.) — the
 * field key itself, not just its displayed value, since it doubles as the
 * `sort_by` value sent to GET .../items (GitHub issue #77) and mirrors
 * MediaItemController::SORTABLE_COLUMNS. Originally LibraryDetailPage-only;
 * exported here (GitHub issue #100) so SearchPage's mixed-media-type
 * results table can show the same per-row field.
 */
export function subtitleField(mediaType: MediaType): 'authors' | 'artist' | 'director' {
  switch (mediaType) {
    case 'book':
      return 'authors'
    case 'cd':
      return 'artist'
    case 'dvd_bluray':
      return 'director'
  }
}

/** Backend serializes `date`-cast columns as full ISO datetimes (e.g. "2021-05-04T00:00:00.000000Z") — trim to the plain date both an <input type="date"> and the read view want. */
export function dateOnly(value: unknown): string {
  return typeof value === 'string' ? value.slice(0, 10) : ''
}

/**
 * A price/monetary value with an actual currency symbol (GitHub issue #107)
 * instead of a bare, unit-less number or a spelled-out ISO code — "93,49 €"
 * rather than "93.49" or "93.49 EUR". Shared by every place in the app that
 * displays a price (StatisticsPage.tsx's total_value, ReportDetailPage.tsx's
 * per-item price and price-based top lists) so they can't drift into
 * showing the same kind of value three different ways again, which is
 * exactly what #107 reported.
 *
 * `currency` is deliberately the caller's responsibility to resolve first
 * (StatisticsPage.tsx, unlike a single item's own known `currency`, only
 * passes one when `currency_mismatch` is false — a mismatched library's sum
 * mixes currencies, so labeling it with just one of them would be
 * misleading) — this function only ever formats, never decides whether a
 * currency is trustworthy to show. `Intl.NumberFormat`'s 'currency' style
 * renders the locale-appropriate symbol — falls back to the plain number if
 * `currency` isn't a real ISO 4217 code Intl recognizes, since both a
 * media item's own `currency` (MediaItemController::rulesFor()) and the
 * admin-configured default (AdminSettingsController::updateStatistics())
 * are free-text fields with no whitelist and could hold anything up to
 * three characters. See PdfExportService::formatPrice() for the PHP
 * equivalent used by the PDF export (GitHub issue #87).
 */
export function formatPrice(price: number | string | null, currency: string | null, language: string): string {
  if (price === null) return '—'
  if (currency) {
    try {
      return new Intl.NumberFormat(language, { style: 'currency', currency }).format(Number(price))
    } catch {
      // Not a currency code Intl recognizes — fall through to the plain number.
    }
  }
  return String(price)
}

/** Formats a duration in seconds as "M:SS" (or "H:MM:SS" past an hour) — used for a CD's `runtime_seconds` and each track's `duration_seconds` (GitHub issue #48). Raw seconds (e.g. "2652") isn't something a person reads at a glance the way "44:12" is. */
export function formatDuration(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60
  const paddedSeconds = String(seconds).padStart(2, '0')

  return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${paddedSeconds}` : `${minutes}:${paddedSeconds}`
}

export function valuesFromItem(item: MediaItem, specs: FieldSpec[]): Record<string, string> {
  return Object.fromEntries(
    specs.map((f) => {
      const raw = item[f.key]
      if (raw === null || raw === undefined) return [f.key, '']
      return [f.key, f.type === 'date' ? dateOnly(raw) : String(raw)]
    })
  )
}

export function payloadFromValues(values: Record<string, string>, specs: FieldSpec[]): Record<string, string | number | null> {
  return Object.fromEntries(
    specs.map((f) => {
      const raw = values[f.key] ?? ''
      if (raw === '') return [f.key, null]
      return [f.key, f.type === 'number' ? Number(raw) : raw]
    })
  )
}
