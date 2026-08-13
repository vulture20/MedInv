import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'

type MediaType = 'book' | 'cd' | 'dvd_bluray'

interface LibraryRef {
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

interface FieldSpec {
  key: keyof MediaItem
  type: FieldType
  required?: boolean
}

/** Mirrors MediaItemController::rulesFor() field-for-field (minus `ean`, which is read-only here too — see this component's docblock). */
const FIELD_SPECS: Record<MediaType, FieldSpec[]> = {
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
function dateOnly(value: unknown): string {
  return typeof value === 'string' ? value.slice(0, 10) : ''
}

function valuesFromItem(item: MediaItem, specs: FieldSpec[]): Record<string, string> {
  return Object.fromEntries(
    specs.map((f) => {
      const raw = item[f.key]
      if (raw === null || raw === undefined) return [f.key, '']
      return [f.key, f.type === 'date' ? dateOnly(raw) : String(raw)]
    })
  )
}

function payloadFromValues(values: Record<string, string>, specs: FieldSpec[]): Record<string, string | number | null> {
  return Object.fromEntries(
    specs.map((f) => {
      const raw = values[f.key] ?? ''
      if (raw === '') return [f.key, null]
      return [f.key, f.type === 'number' ? Number(raw) : raw]
    })
  )
}

interface Props {
  library: LibraryRef
  item: MediaItem | null
  /** Every library visible to the user (GET /libraries) — move targets are filtered from this. */
  libraries: LibraryRef[]
  onClose: () => void
  onUpdated: (item: MediaItem) => void
  onDeleted: () => void
  onMoved: () => void
}

/**
 * Media item detail/edit/delete/move dialog, opened by clicking an item in
 * LibraryDetailPage's list. A native <dialog>, themed the same way as
 * PluginsPage's settings dialog (GitHub issue #29) rather than a bespoke
 * modal implementation.
 */
export function MediaItemDetailDialog({ library, item, libraries, onClose, onUpdated, onDeleted, onMoved }: Props) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [editing, setEditing] = useState(false)
  const [values, setValues] = useState<Record<string, string>>({})
  const [targetLibraryId, setTargetLibraryId] = useState<number | ''>('')
  const [error, setError] = useState<string | null>(null)

  const specs = FIELD_SPECS[library.media_type]

  useEffect(() => {
    if (item) {
      setEditing(false)
      setError(null)
      setValues(valuesFromItem(item, specs))
      dialogRef.current?.showModal()
    } else {
      dialogRef.current?.close()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item])

  // Mirrors LibraryAccessService::canWrite() (admin or owner) — same client-side pattern as LibrariesPage.tsx's canDelete().
  const canWrite = user?.level === 'admin' || library.owner.id === user?.id

  const moveTargets = libraries.filter(
    (lib) => lib.id !== library.id && lib.media_type === library.media_type && (user?.level === 'admin' || lib.owner.id === user?.id)
  )

  function describeSaveError(err: unknown): string {
    if (!isAxiosError(err)) return t('errors.generic')
    if (err.response?.status === 409) return t('capture.duplicate')
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
    if (errors) return Object.values(errors).flat().join(' ')
    return t('errors.generic')
  }

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (!item) return
    setError(null)
    try {
      const { data } = await apiClient.put<MediaItem>(`/libraries/${library.id}/items/${item.id}`, payloadFromValues(values, specs))
      onUpdated(data)
      setEditing(false)
    } catch (err) {
      setError(describeSaveError(err))
    }
  }

  async function remove() {
    if (!item) return
    if (!window.confirm(t('mediaItem.confirmDelete', { title: item.title }))) return
    await apiClient.delete(`/libraries/${library.id}/items/${item.id}`)
    onDeleted()
  }

  async function move() {
    if (!item || targetLibraryId === '') return
    setError(null)
    try {
      await apiClient.post(`/libraries/${library.id}/items/${item.id}/move`, { target_library_id: targetLibraryId })
      onMoved()
    } catch (err) {
      setError(describeSaveError(err))
    }
  }

  async function uploadCover(file: File | undefined) {
    if (!item || !file) return
    setError(null)
    const form = new FormData()
    form.append('cover', file)
    try {
      const { data } = await apiClient.post<MediaItem>(`/libraries/${library.id}/items/${item.id}/cover`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      onUpdated(data)
    } catch (err) {
      setError(describeSaveError(err))
    }
  }

  async function removeCover() {
    if (!item) return
    if (!window.confirm(t('mediaItem.confirmRemoveCover'))) return
    setError(null)
    try {
      const { data } = await apiClient.delete<MediaItem>(`/libraries/${library.id}/items/${item.id}/cover`)
      onUpdated(data)
    } catch (err) {
      setError(describeSaveError(err))
    }
  }

  return (
    <dialog ref={dialogRef} onClose={onClose} className="media-item-dialog">
      {item && (
        <>
          <h3>{item.title}</h3>
          {error && <p role="alert">{error}</p>}

          {item.cover_path && (
            <img
              className="media-item-dialog__cover"
              src={`${apiClient.defaults.baseURL}/libraries/${library.id}/items/${item.id}/cover`}
              crossOrigin="use-credentials"
              alt=""
            />
          )}

          {editing && canWrite && (
            <div className="media-item-dialog__cover-actions">
              <label className="media-item-dialog__cover-upload-label">
                {t('mediaItem.uploadCover')}
                <input
                  type="file"
                  accept="image/*"
                  onChange={(e) => {
                    void uploadCover(e.target.files?.[0])
                    e.target.value = ''
                  }}
                />
              </label>
              {item.cover_path && (
                <button type="button" onClick={() => void removeCover()}>
                  {t('mediaItem.removeCover')}
                </button>
              )}
            </div>
          )}

          {editing ? (
            <form onSubmit={(e) => void save(e)}>
              {specs.map((field) => (
                <label key={field.key}>
                  {t(`mediaItem.fields.${field.key}`)}
                  {field.type === 'textarea' ? (
                    <textarea
                      value={values[field.key] ?? ''}
                      onChange={(e) => setValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                    />
                  ) : (
                    <input
                      type={field.type}
                      required={field.required}
                      step={field.type === 'number' ? 'any' : undefined}
                      value={values[field.key] ?? ''}
                      onChange={(e) => setValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                    />
                  )}
                </label>
              ))}
              <div className="media-item-dialog__actions">
                <button type="submit">{t('admin.actions.save')}</button>
                <button type="button" onClick={() => setEditing(false)}>
                  {t('admin.actions.cancel')}
                </button>
              </div>
            </form>
          ) : (
            <dl className="media-item-dialog__details">
              <dt>{t('mediaItem.fields.ean')}</dt>
              <dd>{item.ean}</dd>
              {specs
                .filter((f) => f.key !== 'title')
                .map((field) => (
                  <div key={field.key} className="media-item-dialog__row">
                    <dt>{t(`mediaItem.fields.${field.key}`)}</dt>
                    <dd>{field.type === 'date' ? dateOnly(item[field.key]) || '—' : String(item[field.key] ?? '') || '—'}</dd>
                  </div>
                ))}
            </dl>
          )}

          {!editing && (
            <div className="media-item-dialog__actions">
              {canWrite && (
                <button type="button" onClick={() => setEditing(true)}>
                  {t('admin.actions.edit')}
                </button>
              )}
              {canWrite && (
                <button type="button" className="media-item-dialog__delete" onClick={() => void remove()}>
                  {t('libraries.delete')}
                </button>
              )}
              <button type="button" onClick={onClose}>
                {t('admin.actions.cancel')}
              </button>
            </div>
          )}

          {!editing && canWrite && moveTargets.length > 0 && (
            <div className="media-item-dialog__move">
              <label>
                {t('mediaItem.moveToLibrary')}
                <select value={targetLibraryId} onChange={(e) => setTargetLibraryId(e.target.value ? Number(e.target.value) : '')}>
                  <option value="">{t('mediaItem.selectLibrary')}</option>
                  {moveTargets.map((lib) => (
                    <option key={lib.id} value={lib.id}>
                      {lib.name}
                    </option>
                  ))}
                </select>
              </label>
              <button type="button" disabled={targetLibraryId === ''} onClick={() => void move()}>
                {t('mediaItem.move')}
              </button>
            </div>
          )}
        </>
      )}
    </dialog>
  )
}
