import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { FIELD_SPECS, payloadFromValues, valuesFromItem, type LibraryRef, type MediaItem, type Track } from './mediaItemFields'
import { TrackListEditor } from './TrackListEditor'

interface Props {
  library: LibraryRef
  /** Set when opened from CapturePage's `no_match` dead-end (briefing 7.1/7.2) — pre-fills the scanned code so it doesn't have to be retyped. Left editable regardless, since a hardware/camera scan can misread a digit. */
  initialEan?: string
  /**
   * GitHub issue #151: set when opened from CapturePage's free-text
   * metadata-search result ("erfassen ohne EAN") — pre-fills every
   * FIELD_SPECS value (and `tracks` for a CD) from the chosen candidate's
   * own `attributes`, the same shape MetadataMergeReview.tsx already deals
   * in. `ean` is deliberately *not* read from here even if the candidate
   * happens to carry one (a free-text search result's `ean` is always
   * null per every MetadataProviderInterface::search() implementation) —
   * left for the user to fill in if they actually know a real one, and
   * auto-generated as a `NoEAN-...` placeholder server-side otherwise.
   */
  initialAttributes?: Record<string, unknown>
  open: boolean
  onClose: () => void
  onCreated: (item: MediaItem) => void
}

/**
 * Manual single-item capture (briefing 7.1): GET /libraries/{library}/items
 * already had a working create endpoint (MediaItemController::store()) with
 * no frontend caller anywhere — this is that caller. Three entry points
 * reuse it: LibraryDetailPage's "add item manually" button (no prefill),
 * CapturePage's `no_match` result (EAN prefilled from the scan, GitHub
 * issue #17), and CapturePage's free-text search result (fields prefilled
 * from the chosen candidate, no EAN — GitHub issue #151).
 */
export function CreateMediaItemDialog({ library, initialEan, initialAttributes, open, onClose, onCreated }: Props) {
  const { t, i18n } = useTranslation()
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [ean, setEan] = useState('')
  const [values, setValues] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  // GitHub issue #75 — held locally rather than uploaded on selection like
  // MediaItemDetailDialog's uploadCover() does, since there's no item id to
  // attach it to yet; actually uploaded as a second request in submit()
  // below, right after the item itself is created.
  const [coverFile, setCoverFile] = useState<File | null>(null)
  // GitHub issue #92: a CD's track list, editable right at manual entry —
  // most useful right after a capture-flow `no_match` dead end, previously
  // the one case where typing a track list by hand was most likely to be
  // wanted but least possible until the item was saved and reopened for
  // editing. See TrackListEditor.tsx (shared with MediaItemDetailDialog.tsx,
  // GitHub issue #90) for why this isn't part of the FIELD_SPECS loop below.
  const [tracks, setTracks] = useState<Track[]>([])

  const specs = FIELD_SPECS[library.media_type]

  useEffect(() => {
    if (open) {
      setEan(initialEan ?? '')
      // GitHub issue #151: valuesFromItem() already does exactly the
      // per-spec-key extraction+stringification a candidate's `attributes`
      // needs here too — it only ever reads `item[f.key]`, tolerant of a
      // candidate not carrying every MediaItem field a real saved item
      // would, hence the cast rather than a second, near-identical helper.
      setValues(initialAttributes ? valuesFromItem(initialAttributes as unknown as MediaItem, specs) : {})
      setError(null)
      setSaving(false)
      setCoverFile(null)
      setTracks((initialAttributes?.tracks as Track[] | undefined) ?? [])
      dialogRef.current?.showModal()
    } else {
      dialogRef.current?.close()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialEan, initialAttributes])

  // GitHub issue #156: errors.actionFailed, not errors.generic — the
  // latter's wording ("Login failed...") is specific to LoginPage.tsx's own
  // actual failed-login case and was confusingly reused here for an
  // unrelated action (a real 500 while saving an item read as if the
  // login itself had somehow failed).
  function describeApiError(err: unknown): string {
    if (!isAxiosError(err)) return t('errors.actionFailed')
    if (err.response?.status === 409) return t('capture.duplicate')
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
    if (errors) return Object.values(errors).flat().join(' ')
    return t('errors.actionFailed')
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    setSaving(true)
    try {
      const payload: Record<string, unknown> = { ean, ...payloadFromValues(values, specs) }
      // GitHub issue #92: no "was it touched" distinction needed here unlike
      // MediaItemDetailDialog.tsx's save() — there's no pre-existing item
      // whose runtime_seconds a blank tracks list could ever accidentally
      // overwrite, so MediaItemService::withDerivedRuntime() (already run by
      // MediaItemController::store()) can simply be handed whatever's here.
      if (library.media_type === 'cd' && tracks.length > 0) {
        payload.tracks = tracks
      }
      let { data } = await apiClient.post<MediaItem>(`/libraries/${library.id}/items`, payload)
      // GitHub issue #75: a second request, hidden behind this same submit,
      // mirroring MediaItemDetailDialog's uploadCover() now that the item
      // (and its id) exists. A failure here doesn't roll back or block the
      // item creation that already succeeded — retrying the whole form
      // would just 409 on the now-duplicate EAN — so it's surfaced
      // separately via window.alert() (same pattern LanguagesPage.tsx/
      // TemplatesPage.tsx/UsersPage.tsx use for a failure alongside an
      // otherwise-successful action) rather than through `error` above,
      // which the dialog closing right after would hide immediately.
      if (coverFile) {
        try {
          const form = new FormData()
          form.append('cover', coverFile)
          const coverRes = await apiClient.post<MediaItem>(`/libraries/${library.id}/items/${data.id}/cover`, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
          })
          data = coverRes.data
        } catch {
          window.alert(t('mediaItem.createCoverUploadFailed'))
        }
      }
      onCreated(data)
    } catch (err) {
      setError(describeApiError(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      // See MediaItemDetailDialog.tsx's identical handler for why this is
      // safe against misfiring on a normal in-dialog click.
      onClick={(e) => e.target === e.currentTarget && onClose()}
      className="media-item-dialog"
    >
      {open && (
        <>
          <h3>{t('mediaItem.createTitle')}</h3>
          {error && <p role="alert">{error}</p>}
          <form onSubmit={(e) => void submit(e)}>
            {/* GitHub issue #75: uploaded as a second request in submit()
                above right after the item itself is created — there's no
                item id to attach a cover to yet, unlike
                MediaItemDetailDialog's identically-styled upload control,
                which uploads immediately on selection. */}
            <div className="media-item-dialog__cover-actions">
              <label className="media-item-dialog__cover-upload-label">
                {t('mediaItem.uploadCover')}
                <input type="file" accept="image/*" onChange={(e) => setCoverFile(e.target.files?.[0] ?? null)} />
              </label>
            </div>
            <label>
              {t('mediaItem.fields.ean')}
              {/* GitHub issue #151: no longer required — MediaItemController::store() generates a NoEAN-... placeholder when this is left empty. */}
              <input value={ean} onChange={(e) => setEan(e.target.value)} placeholder={t('mediaItem.eanOptionalHint')} />
            </label>
            {specs.map((field) => (
              <label key={field.key}>
                {t(`mediaItem.fields.${field.key}`)}
                {field.type === 'textarea' ? (
                  <textarea
                    value={values[field.key] ?? ''}
                    onChange={(e) => setValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                  />
                ) : field.type === 'select' ? (
                  // GitHub issue #114 — a fixed, browser-provided value list
                  // (e.g. ISO 4217 currency codes) instead of free text.
                  <select
                    required={field.required}
                    value={values[field.key] ?? ''}
                    onChange={(e) => setValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                  >
                    <option value="">{t('mediaItem.selectValue')}</option>
                    {field.options?.map((option) => (
                      <option key={option} value={option}>
                        {field.formatOption ? field.formatOption(option, i18n.language) : option}
                      </option>
                    ))}
                  </select>
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

            {/* GitHub issue #92 — see TrackListEditor.tsx's own docblock for why this needed to be added here too, alongside #90's edit-dialog version. */}
            {library.media_type === 'cd' && <TrackListEditor tracks={tracks} onChange={setTracks} />}

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
