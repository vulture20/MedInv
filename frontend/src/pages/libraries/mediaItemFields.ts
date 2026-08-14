export type MediaType = 'book' | 'cd' | 'dvd_bluray'

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
  price?: number | string | null
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
  // dvd_bluray
  medium?: string | null
  runtime_minutes?: number | null
  languages?: string | null
  cast?: string | null
  director?: string | null
  production_year?: number | null
}

type FieldType = 'text' | 'textarea' | 'number' | 'date'

export interface FieldSpec {
  key: keyof MediaItem
  type: FieldType
  required?: boolean
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
    { key: 'isbn10', type: 'text' },
    { key: 'isbn13', type: 'text' },
    { key: 'description', type: 'textarea' },
  ],
  cd: [
    { key: 'title', type: 'text', required: true },
    { key: 'artist', type: 'text' },
    { key: 'medium', type: 'text' },
    { key: 'asin', type: 'text' },
    { key: 'disc_count', type: 'number' },
    { key: 'release_date', type: 'date' },
    { key: 'price', type: 'number' },
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
    { key: 'description', type: 'textarea' },
  ],
}

/** Backend serializes `date`-cast columns as full ISO datetimes (e.g. "2021-05-04T00:00:00.000000Z") — trim to the plain date both an <input type="date"> and the read view want. */
export function dateOnly(value: unknown): string {
  return typeof value === 'string' ? value.slice(0, 10) : ''
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
