import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { FIELD_SPECS, dateOnly, formatDuration, payloadFromValues, valuesFromItem, type LibraryRef, type MediaItem } from './mediaItemFields'
import { MetadataMergeReview, type MergedMetadata } from '../capture/MetadataMergeReview'

export type { MediaItem } from './mediaItemFields'

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
  const coverDialogRef = useRef<HTMLDialogElement>(null)
  const [editing, setEditing] = useState(false)
  const [values, setValues] = useState<Record<string, string>>({})
  const [targetLibraryId, setTargetLibraryId] = useState<number | ''>('')
  const [error, setError] = useState<string | null>(null)
  // Kept separate from `error` so a "this item already exists there" (or
  // similar) failure renders right next to the "move to library" control
  // that caused it, instead of at the top of the dialog where it read as
  // unrelated to the edit form/cover controls shown above it.
  const [moveError, setMoveError] = useState<string | null>(null)
  // GitHub issue #45: a fullscreen view of the cover, opened by clicking
  // the small one in the details view below.
  const [coverFullscreen, setCoverFullscreen] = useState(false)
  // GitHub issue #56: re-running the metadata lookup for an already
  // captured item. 'candidates' drives the same MetadataMergeReview the
  // initial capture flow uses (per explicit user instruction: per-field
  // picking, not a blind overwrite) — kept separate from `error`/`values`
  // so it doesn't interfere with the edit form's own state.
  const [refreshStatus, setRefreshStatus] = useState<'idle' | 'loading' | 'no_match' | 'candidates'>('idle')
  const [refreshMerged, setRefreshMerged] = useState<MergedMetadata | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)

  const specs = FIELD_SPECS[library.media_type]

  useEffect(() => {
    if (item) {
      setEditing(false)
      setError(null)
      setMoveError(null)
      setCoverFullscreen(false)
      setRefreshStatus('idle')
      setRefreshMerged(null)
      setRefreshError(null)
      setValues(valuesFromItem(item, specs))
      dialogRef.current?.showModal()
    } else {
      dialogRef.current?.close()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item])

  // A nested <dialog> (native <dialog> elements stack correctly — opening
  // one while another is already modal simply makes it the new topmost
  // one, and closing it returns interaction to the one underneath) rather
  // than a bespoke overlay, specifically so Esc-to-close comes for free
  // from the browser instead of needing its own keydown listener (see
  // this component's own top-level docblock precedent: PluginsPage's
  // settings dialog and this dialog itself already rely on native <dialog>
  // behavior the same way).
  useEffect(() => {
    if (coverFullscreen) {
      coverDialogRef.current?.showModal()
    } else {
      coverDialogRef.current?.close()
    }
  }, [coverFullscreen])

  // Mirrors LibraryAccessService::canWrite() (admin or owner) — same client-side pattern as LibrariesPage.tsx's canDelete().
  const canWrite = user?.level === 'admin' || library.owner.id === user?.id

  const moveTargets = libraries.filter(
    (lib) => lib.id !== library.id && lib.media_type === library.media_type && (user?.level === 'admin' || lib.owner.id === user?.id)
  )

  function describeApiError(err: unknown): string {
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
      setError(describeApiError(err))
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
    setMoveError(null)
    try {
      await apiClient.post(`/libraries/${library.id}/items/${item.id}/move`, { target_library_id: targetLibraryId })
      onMoved()
    } catch (err) {
      setMoveError(describeApiError(err))
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
      setError(describeApiError(err))
    }
  }

  /**
   * Re-queries every enabled provider by the item's stored EAN (GitHub
   * issue #56) — e.g. a provider that failed on the original capture, a
   * new plugin enabled since, or improved source data. Shares its result
   * shape ({status, merged}) with BulkImportService::resolveOne(), so the
   * 'candidates' branch below can hand it straight to the same
   * MetadataMergeReview component the capture flow already uses.
   */
  async function refreshMetadata() {
    if (!item) return
    setRefreshError(null)
    setRefreshStatus('loading')
    try {
      const { data } = await apiClient.get<{ status: string; merged: MergedMetadata }>(
        `/libraries/${library.id}/items/${item.id}/metadata/refresh`
      )
      setRefreshMerged(data.status === 'candidates' ? data.merged : null)
      setRefreshStatus(data.status === 'candidates' ? 'candidates' : 'no_match')
    } catch (err) {
      setRefreshError(describeApiError(err))
      setRefreshStatus('idle')
    }
  }

  /** Applies the user's per-field picks from MetadataMergeReview onto the existing item (POST, not the create-path's PUT-equivalent). */
  async function confirmRefresh(attributes: Record<string, unknown>, coverUrl: string | null) {
    if (!item) return
    setRefreshError(null)
    try {
      const { data } = await apiClient.post<MediaItem>(`/libraries/${library.id}/items/${item.id}/metadata/refresh`, {
        attributes,
        cover_url: coverUrl ?? undefined,
      })
      onUpdated(data)
      setRefreshStatus('idle')
      setRefreshMerged(null)
    } catch (err) {
      setRefreshError(describeApiError(err))
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
      setError(describeApiError(err))
    }
  }

  return (
    <>
    <dialog
      ref={dialogRef}
      onClose={onClose}
      // Clicking the ::backdrop (outside the dialog's own content) fires a
      // click event whose target is the <dialog> element itself, never a
      // descendant — a click on any actual content inside always has that
      // content element as the target instead, so this can't misfire on a
      // normal in-dialog click. Native <dialog> doesn't close on backdrop
      // click by default, only Esc/a real close()/form method="dialog".
      onClick={(e) => e.target === e.currentTarget && onClose()}
      className="media-item-dialog"
    >
      {item && (
        <>
          <h3>{item.title}</h3>
          {error && <p role="alert">{error}</p>}

          {item.cover_path && (
            <button
              type="button"
              className="media-item-dialog__cover-button"
              onClick={() => setCoverFullscreen(true)}
              aria-label={t('mediaItem.viewCoverFullscreen')}
            >
              <img
                className="media-item-dialog__cover"
                src={`${apiClient.defaults.baseURL}/libraries/${library.id}/items/${item.id}/cover`}
                crossOrigin="use-credentials"
                alt=""
              />
            </button>
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
                    <dd>
                      {field.key === 'runtime_seconds' ? (
                        typeof item.runtime_seconds === 'number' ? (
                          <>
                            {formatDuration(item.runtime_seconds)}
                            {/* GitHub issue #48: flags a runtime summed from the track list rather than reported directly by a metadata source. */}
                            {item.runtime_computed && <span className="hint"> {t('mediaItem.computedHint')}</span>}
                          </>
                        ) : (
                          '—'
                        )
                      ) : field.type === 'date' ? (
                        dateOnly(item[field.key]) || '—'
                      ) : (
                        String(item[field.key] ?? '') || '—'
                      )}
                    </dd>
                  </div>
                ))}
            </dl>
          )}

          {/* GitHub issue #48: a CD's track listing, read-only — not part of the FIELD_SPECS-driven edit form above, since it isn't a single scalar value a plain input can represent. */}
          {!editing && library.media_type === 'cd' && item.tracks && item.tracks.length > 0 && (
            <>
              <h4>{t('mediaItem.tracklist')}</h4>
              <ol className="media-item-dialog__tracklist">
                {item.tracks.map((track, index) => (
                  <li key={track.position ?? index}>
                    <span className="media-item-dialog__track-title">{track.title || '—'}</span>
                    {typeof track.duration_seconds === 'number' && (
                      <span className="media-item-dialog__track-duration">{formatDuration(track.duration_seconds)}</span>
                    )}
                  </li>
                ))}
              </ol>
            </>
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
              {canWrite && (
                <button type="button" disabled={refreshStatus === 'loading'} onClick={() => void refreshMetadata()}>
                  {t('mediaItem.refreshMetadata')}
                </button>
              )}
              <button type="button" onClick={onClose}>
                {t('admin.actions.cancel')}
              </button>
            </div>
          )}

          {refreshError && <p role="alert">{refreshError}</p>}
          {refreshStatus === 'no_match' && <p className="hint">{t('capture.noMatch')}</p>}
          {refreshStatus === 'candidates' && refreshMerged && (
            <MetadataMergeReview
              ean={item.ean}
              mediaType={library.media_type}
              merged={refreshMerged}
              onConfirm={(attributes, coverUrl) => void confirmRefresh(attributes, coverUrl)}
              onReject={() => {
                setRefreshStatus('idle')
                setRefreshMerged(null)
              }}
            />
          )}

          {!editing && canWrite && moveTargets.length > 0 && (
            <div className="media-item-dialog__move">
              <label>
                {t('mediaItem.moveToLibrary')}
                <select
                  value={targetLibraryId}
                  onChange={(e) => {
                    setTargetLibraryId(e.target.value ? Number(e.target.value) : '')
                    setMoveError(null)
                  }}
                >
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
              {moveError && (
                <p role="alert" className="media-item-dialog__move-error">
                  {moveError}
                </p>
              )}
            </div>
          )}
        </>
      )}
    </dialog>

    {/*
      GitHub issue #45: a separate, sibling <dialog> rather than nesting it
      inside the one above — showModal()'s browser-managed stacking
      ("top layer") doesn't require DOM nesting to stack correctly, and a
      sibling avoids any inherited stacking-context/CSS-containment
      surprises from living inside an already-open modal. Any click
      anywhere in this dialog closes it — both the backdrop *and* the
      enlarged cover itself (per the issue: "ein weiterer Klick auf das
      vergrößerte Cover... schließt") — unlike the backdrop-click-only
      pattern the dialog above uses, there's no other interactive content
      in here a click could ever need to *not* close on. Esc closes it too,
      for free, via native <dialog> behavior (fires onClose, kept in sync
      with `coverFullscreen` the same way the outer dialog already is).
    */}
    {item?.cover_path && (
      <dialog ref={coverDialogRef} className="media-item-cover-dialog" onClose={() => setCoverFullscreen(false)} onClick={() => setCoverFullscreen(false)}>
        <img
          className="media-item-cover-dialog__image"
          src={`${apiClient.defaults.baseURL}/libraries/${library.id}/items/${item.id}/cover`}
          crossOrigin="use-credentials"
          alt=""
        />
      </dialog>
    )}
    </>
  )
}
