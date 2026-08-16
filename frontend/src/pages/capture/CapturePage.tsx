import { lazy, Suspense, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { CreateMediaItemDialog } from '../libraries/CreateMediaItemDialog'
import type { LibraryRef, MediaItem } from '../libraries/mediaItemFields'
import { MetadataMergeReview, ProviderStatusList, type MergedMetadata, type ProviderStatus } from './MetadataMergeReview'

// The barcode decoder (@zxing/library) is a heavy dependency (~800KB) that
// most captures never touch — hardware-scanner and manual entry cover the
// common case. Loaded on demand only once the camera is actually opened,
// instead of adding that weight to every visit of this page.
const CameraScanner = lazy(() => import('./CameraScanner').then((m) => ({ default: m.CameraScanner })))

type Library = LibraryRef

interface ScanResult {
  status: 'duplicate' | 'no_match' | 'candidates'
  ean: string
  /** Field-by-field comparison across every provider that matched (see MetadataMerger) — what CapturePage's UI actually renders/submits for a 'candidates' result. */
  merged?: MergedMetadata
  /** GitHub issue #53: per-provider ok/no_match/failed — absent for a 'duplicate' result, since no lookup ever ran. */
  provider_statuses?: ProviderStatus[]
}

/**
 * A ScanResult plus the library it was actually scanned against, captured
 * once at scan time rather than read from whatever's currently selected in
 * the library dropdown when the result is later rendered/confirmed —
 * scanning is asynchronous and results accumulate in a list, so the
 * selector can easily have moved on to a different library (a different
 * media_type, even) by the time an earlier result is still pending review.
 */
interface PendingResult extends ScanResult {
  library: Library
}

/**
 * Bulk capture (briefing 7.2). The hardware-scanner, manual-entry and
 * camera paths all funnel into `scanCode()` — a hardware scanner types the
 * code into `codeInput` followed by Enter (its native behavior), which
 * submits the form exactly like a manually typed EAN would, and
 * `CameraScanner` calls the exact same handler with its decoded result.
 *
 * Card layout matches SettingsPage.tsx's (.panel-page/.panel-card — see
 * that file/index.css's shared docblock), per explicit user request to
 * align the two rather than each carrying its own bespoke look. The scan/
 * type input is deliberately never hidden behind a tab or a reveal toggle
 * the way camera/text-file are: a hardware barcode scanner "types" into
 * whichever input has focus and presses Enter on its own, so this input
 * has to stay mounted and reachable at all times for that to keep working,
 * not just when a "manual entry" mode happens to be selected.
 */
export function CapturePage() {
  const { t } = useTranslation()
  const [libraries, setLibraries] = useState<Library[]>([])
  const [libraryId, setLibraryId] = useState<number | null>(null)
  const [codeInput, setCodeInput] = useState('')
  const [results, setResults] = useState<PendingResult[]>([])
  const [file, setFile] = useState<File | null>(null)
  const [cameraOpen, setCameraOpen] = useState(false)
  // Manual creation dead-end fix (GitHub issue #17): a `no_match` result
  // used to be a pure dead end — this reuses the same create dialog
  // LibraryDetailPage's standalone "add manually" button opens, pre-filled
  // with the scanned EAN so it doesn't have to be retyped (still editable,
  // since a misread digit is exactly the kind of thing that causes a
  // no_match in the first place).
  const [creatingForEan, setCreatingForEan] = useState<string | null>(null)

  useEffect(() => {
    void apiClient.get<Library[]>('/libraries').then(({ data }) => {
      setLibraries(data)
      if (data.length) setLibraryId(data[0].id)
    })
  }, [])

  async function scanCode(code: string) {
    const library = libraries.find((l) => l.id === libraryId)
    if (!library || !code.trim()) return
    const { data } = await apiClient.post<ScanResult>(`/libraries/${library.id}/capture/scan`, {
      ean: code.trim(),
    })
    setResults((prev) => [{ ...data, library }, ...prev])
  }

  async function submitCode(e: React.FormEvent) {
    e.preventDefault()
    await scanCode(codeInput)
    setCodeInput('')
  }

  async function submitTextFile(e: React.FormEvent) {
    e.preventDefault()
    const library = libraries.find((l) => l.id === libraryId)
    if (!library || !file) return
    const form = new FormData()
    form.append('file', file)
    const { data } = await apiClient.post<ScanResult[]>(`/libraries/${library.id}/capture/textfile`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    setResults((prev) => [...data.map((r) => ({ ...r, library })), ...prev])
    setFile(null)
  }

  async function confirmMerged(result: PendingResult, attributes: Record<string, unknown>, coverUrl: string | null) {
    await apiClient.post(`/libraries/${result.library.id}/metadata/import`, {
      attributes,
      cover_url: coverUrl ?? undefined,
    })
    setResults((prev) => prev.filter((r) => r.ean !== result.ean))
  }

  /** Removes a result the user has no other action to take on (a 'duplicate' has none at all; a 'no_match' has "add manually" but may just as well not be wanted) — 'candidates' already has its own dismissal via MetadataMergeReview's "reject all". */
  function dismissResult(ean: string) {
    setResults((prev) => prev.filter((r) => r.ean !== ean))
  }

  const activeLibrary = libraries.find((l) => l.id === libraryId)

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('capture.title')}</h1>
        <p className="hint">{t('capture.subtitle')}</p>
      </header>

      <section className="panel-card">
        <h2>{t('libraries.title')}</h2>
        <p className="hint">{t('capture.libraryHint')}</p>
        <select className="panel-select" value={libraryId ?? ''} onChange={(e) => setLibraryId(Number(e.target.value))}>
          {libraries.map((lib) => (
            <option key={lib.id} value={lib.id}>
              {lib.name} — {t(`libraries.mediaType.${lib.media_type}`)}
            </option>
          ))}
        </select>
      </section>

      <section className="panel-card">
        <h2>{t('capture.methodTitle')}</h2>
        <p className="hint">{t('capture.methodHint')}</p>

        {/* Hardware scanner + manual entry share this one input (7.2) — see this component's own docblock for why it can never be hidden behind a reveal toggle the way camera/text-file below are. */}
        <form className="capture-scan-form" onSubmit={submitCode}>
          <input
            className="panel-select capture-scan-form__input"
            value={codeInput}
            onChange={(e) => setCodeInput(e.target.value)}
            placeholder={t('capture.eanIsbnPlaceholder')}
            aria-label={t('capture.scan')}
            autoFocus
          />
          <button type="submit">{t('capture.scan')}</button>
        </form>

        <div className="capture-divider">
          <span>{t('capture.or')}</span>
        </div>

        <div className="capture-alt-methods">
          {cameraOpen ? (
            <Suspense fallback={<p className="hint">…</p>}>
              <CameraScanner onDecode={(code) => void scanCode(code)} onClose={() => setCameraOpen(false)} />
            </Suspense>
          ) : (
            <button type="button" onClick={() => setCameraOpen(true)}>
              {t('capture.cameraScan')}
            </button>
          )}

          <form className="capture-textfile-form" onSubmit={submitTextFile}>
            <span className="capture-textfile-form__label-text">{t('capture.textFileImport')}</span>
            <input type="file" accept=".txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            <button type="submit" disabled={!file}>
              {t('capture.textFileImport')}
            </button>
          </form>
        </div>
      </section>

      {results.length > 0 && (
        <section className="panel-card">
          <h2>{t('capture.resultsTitle', { count: results.length })}</h2>

          <ul className="capture-results">
            {results.map((result) => (
              <li key={result.ean} className="capture-result">
                <div className="capture-result__header">
                  <span className="capture-result__ean">{result.ean}</span>
                  {result.status === 'duplicate' && <span className="warning warning--danger">{t('capture.duplicate')}</span>}
                  {result.status !== 'candidates' && (
                    <button
                      type="button"
                      className="capture-result__dismiss"
                      aria-label={t('capture.dismiss')}
                      onClick={() => dismissResult(result.ean)}
                    >
                      ×
                    </button>
                  )}
                </div>

                {result.status === 'no_match' && (
                  <p className="capture-result__no-match">
                    {t('capture.noMatch')}{' '}
                    <button type="button" onClick={() => setCreatingForEan(result.ean)}>
                      {t('mediaItem.addManually')}
                    </button>
                  </p>
                )}

                {/* GitHub issue #53: shown for both 'no_match' (where it's most useful — tells apart "genuinely nothing" from "a provider's request failed") and 'candidates'. */}
                {result.provider_statuses && <ProviderStatusList statuses={result.provider_statuses} />}

                {result.status === 'candidates' && result.merged && (
                  <MetadataMergeReview
                    ean={result.ean}
                    mediaType={result.library.media_type}
                    merged={result.merged}
                    onConfirm={(attributes, coverUrl) => void confirmMerged(result, attributes, coverUrl)}
                    onReject={() => dismissResult(result.ean)}
                  />
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      {activeLibrary && (
        <CreateMediaItemDialog
          library={activeLibrary}
          initialEan={creatingForEan ?? undefined}
          open={creatingForEan !== null}
          onClose={() => setCreatingForEan(null)}
          onCreated={(item: MediaItem) => {
            setCreatingForEan(null)
            setResults((prev) => prev.filter((r) => r.ean !== item.ean))
          }}
        />
      )}
    </div>
  )
}
