import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { FIELD_SPECS, payloadFromValues, type LibraryRef, type MediaItem } from './mediaItemFields'

interface Props {
  library: LibraryRef
  /** Set when opened from CapturePage's `no_match` dead-end (briefing 7.1/7.2) — pre-fills the scanned code so it doesn't have to be retyped. Left editable regardless, since a hardware/camera scan can misread a digit. */
  initialEan?: string
  open: boolean
  onClose: () => void
  onCreated: (item: MediaItem) => void
}

/**
 * Manual single-item capture (briefing 7.1): GET /libraries/{library}/items
 * already had a working create endpoint (MediaItemController::store()) with
 * no frontend caller anywhere — this is that caller. Two entry points reuse
 * it: LibraryDetailPage's "add item manually" button (no prefill) and
 * CapturePage's `no_match` result (EAN prefilled from the scan), see
 * GitHub issue #17.
 */
export function CreateMediaItemDialog({ library, initialEan, open, onClose, onCreated }: Props) {
  const { t } = useTranslation()
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [ean, setEan] = useState('')
  const [values, setValues] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const specs = FIELD_SPECS[library.media_type]

  useEffect(() => {
    if (open) {
      setEan(initialEan ?? '')
      setValues({})
      setError(null)
      setSaving(false)
      dialogRef.current?.showModal()
    } else {
      dialogRef.current?.close()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialEan])

  function describeApiError(err: unknown): string {
    if (!isAxiosError(err)) return t('errors.generic')
    if (err.response?.status === 409) return t('capture.duplicate')
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
    if (errors) return Object.values(errors).flat().join(' ')
    return t('errors.generic')
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    setSaving(true)
    try {
      const { data } = await apiClient.post<MediaItem>(`/libraries/${library.id}/items`, {
        ean,
        ...payloadFromValues(values, specs),
      })
      onCreated(data)
    } catch (err) {
      setError(describeApiError(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <dialog ref={dialogRef} onClose={onClose} className="media-item-dialog">
      {open && (
        <>
          <h3>{t('mediaItem.createTitle')}</h3>
          {error && <p role="alert">{error}</p>}
          <form onSubmit={(e) => void submit(e)}>
            <label>
              {t('mediaItem.fields.ean')}
              <input value={ean} onChange={(e) => setEan(e.target.value)} required />
            </label>
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
              <button type="submit" disabled={saving}>
                {t('admin.actions.save')}
              </button>
              <button type="button" onClick={onClose}>
                {t('admin.actions.cancel')}
              </button>
            </div>
          </form>
        </>
      )}
    </dialog>
  )
}
