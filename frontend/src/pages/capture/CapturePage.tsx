import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { Spinner } from '../../components/Spinner'
import { describeError } from '../admin/adminErrors'
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
 *
 * `id` is a client-side-only sequence number, deliberately independent of
 * `ean` — two results legitimately can share the same `ean` (e.g. a
 * rescan after dismissing the first one, or a text file listing a code
 * twice), and both React's list `key` and MetadataMergeReview's radio
 * button `name`s need something that stays unique even then. Keying
 * either of those off `ean` was the actual cause of a reported bug: two
 * results for the same EAN produced two <input type="radio"> groups with
 * the identical `name`, which the browser groups globally regardless of
 * which dialog they're rendered in — selecting an option in one silently
 * cleared the "same" option in the other.
 */
interface PendingResult extends ScanResult {
  library: Library
  id: number
}

/**
 * GitHub issue #93: a placeholder shown from the moment an EAN is submitted
 * (scan-form, camera decode, or one row of an uploaded text file) until the
 * matching lookup actually resolves — `POST .../capture/scan` runs
 * MetadataImportService::lookupMerged() over every enabled provider
 * sequentially before responding at all (briefing 8.3), which used to leave
 * this whole page looking inert while that ran. Same `library`/`id`
 * bookkeeping as PendingResult, but deliberately its own, narrower type: a
 * pending entry only ever has an ean/library, never any of ScanResult's
 * fields, which only exist once the real response lands.
 */
interface PendingLookup {
  ean: string
  library: Library
  id: number
}

/**
 * How long a repeat scan of the *same* EAN is ignored. A hardware barcode
 * scanner "types" its code and presses Enter on its own (see this
 * component's own docblock) — some double-fire on a single trigger pull,
 * and a nervous double-tap does the same manually. Without this, each
 * duplicate submission produced its own PendingResult, and — before the
 * `id`-based key/radio-name fix above — visibly corrupted the review
 * dialog. Deliberately per-EAN, not a blanket cooldown on scanning at all:
 * scanning several different items in quick succession (the normal bulk-
 * capture flow, briefing 7.2) must stay unthrottled.
 */
const EAN_SCAN_THROTTLE_MS = 2000

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
  // GitHub issue #93 — see PendingLookup's own docblock.
  const [pendingLookups, setPendingLookups] = useState<PendingLookup[]>([])
  const [file, setFile] = useState<File | null>(null)
  const [cameraOpen, setCameraOpen] = useState(false)
  // Manual creation dead-end fix (GitHub issue #17): a `no_match` result
  // used to be a pure dead end — this reuses the same create dialog
  // LibraryDetailPage's standalone "add manually" button opens, pre-filled
  // with the scanned EAN so it doesn't have to be retyped (still editable,
  // since a misread digit is exactly the kind of thing that causes a
  // no_match in the first place).
  const [creatingForEan, setCreatingForEan] = useState<string | null>(null)
  // See EAN_SCAN_THROTTLE_MS above. A ref, not state — updating it must never
  // itself trigger a re-render, and scanCode() needs its current value
  // synchronously on every call, not just after the next render.
  const lastScanRef = useRef<{ ean: string; at: number } | null>(null)
  const nextResultId = useRef(0)
  // GitHub issue #89 — see the effect below for why this needs its own ref
  // (not `results.length` compared against 0 directly).
  const codeInputRef = useRef<HTMLInputElement>(null)
  const previousResultsLength = useRef(0)
  // GitHub issue #110 — previously missing entirely, on this effect and on
  // scanCode()/submitTextFile()/confirmMerged() below: a failed request
  // just silently did nothing (the initial library list stayed empty with
  // no explanation, or a scan/import vanished from the pending list with no
  // sign it never actually succeeded), the same gap already fixed on
  // several other pages this session.
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    apiClient
      .get<Library[]>('/libraries')
      .then(({ data }) => {
        setLibraries(data)
        if (data.length) setLibraryId(data[0].id)
      })
      .catch((err) => setError(describeError(err, t)))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /**
   * GitHub issue #89: a hardware barcode scanner "types" its code into
   * whichever input has focus (see this component's own docblock) — once
   * the last pending result is confirmed/dismissed/manually created (all
   * three go through setResults() above/below, so watching `results.length`
   * covers all of them without duplicating a .focus() call into each
   * handler), the EAN/ISBN input should be ready to receive the next scan
   * again without a manual click first. Only on the 1-to-0 transition, not
   * on mount (`results` starts at 0, and the input already has `autoFocus`
   * for that case) or on every render where it merely stays 0 — otherwise
   * this would steal focus from something else on the page (e.g. the
   * library picker) the moment it renders, before any scan ever happened.
   */
  useEffect(() => {
    if (previousResultsLength.current > 0 && results.length === 0) {
      codeInputRef.current?.focus()
    }
    previousResultsLength.current = results.length
  }, [results.length])

  async function scanCode(code: string) {
    const library = libraries.find((l) => l.id === libraryId)
    const ean = code.trim()
    if (!library || !ean) return

    const now = Date.now()
    const last = lastScanRef.current
    if (last && last.ean === ean && now - last.at < EAN_SCAN_THROTTLE_MS) return
    lastScanRef.current = { ean, at: now }

    // GitHub issue #93: visible from submission until the request below
    // actually resolves, so the page doesn't look inert while
    // MetadataImportService::lookupMerged() works through every enabled
    // provider server-side.
    const pendingId = nextResultId.current++
    setPendingLookups((prev) => [{ ean, library, id: pendingId }, ...prev])
    setError(null)
    try {
      const { data } = await apiClient.post<ScanResult>(`/libraries/${library.id}/capture/scan`, { ean })
      setResults((prev) => [{ ...data, library, id: nextResultId.current++ }, ...prev])
    } catch (err) {
      setError(describeError(err, t))
    } finally {
      setPendingLookups((prev) => prev.filter((p) => p.id !== pendingId))
    }
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

    // GitHub issue #93: one pending placeholder per line, not just one for
    // the whole file — POST .../capture/textfile only responds once every
    // line has been looked up server-side (BulkImportService::resolveMany()),
    // so without this the whole batch would still look like nothing is
    // happening until it's entirely done. Split/trim/filter mirrors
    // BulkImportService::parseEanTextFile() exactly, so the placeholders
    // shown here match the codes the backend will actually process one for
    // one.
    const contents = await file.text()
    const eans = contents
      .split(/\r\n|\r|\n/)
      .map((line) => line.trim())
      .filter(Boolean)
    const pendingEntries = eans.map((ean) => ({ ean, library, id: nextResultId.current++ }))
    setPendingLookups((prev) => [...pendingEntries, ...prev])

    const form = new FormData()
    form.append('file', file)
    setFile(null)
    setError(null)
    try {
      const { data } = await apiClient.post<ScanResult[]>(`/libraries/${library.id}/capture/textfile`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setResults((prev) => [...data.map((r) => ({ ...r, library, id: nextResultId.current++ })), ...prev])
    } catch (err) {
      setError(describeError(err, t))
    } finally {
      const pendingIds = new Set(pendingEntries.map((p) => p.id))
      setPendingLookups((prev) => prev.filter((p) => !pendingIds.has(p.id)))
    }
  }

  async function confirmMerged(result: PendingResult, attributes: Record<string, unknown>, coverUrl: string | null, providerKeys: string[]) {
    setError(null)
    try {
      await apiClient.post(`/libraries/${result.library.id}/metadata/import`, {
        attributes,
        cover_url: coverUrl ?? undefined,
        // GitHub issue #74 — see MetadataMergeReview's onConfirm docblock.
        metadata_providers: providerKeys,
      })
      setResults((prev) => prev.filter((r) => r.id !== result.id))
    } catch (err) {
      // Deliberately doesn't remove `result` from `results` on failure
      // (GitHub issue #110) — it used to just vanish from the pending list
      // with no sign the import never actually went through (e.g. a
      // duplicate-EAN 409); leaving the card in place lets the user see
      // the error and retry the same confirm instead of losing the lookup
      // entirely.
      setError(describeError(err, t))
    }
  }

  /** Removes a result the user has no other action to take on (a 'duplicate' has none at all; a 'no_match' has "add manually" but may just as well not be wanted) — 'candidates' already has its own dismissal via MetadataMergeReview's "reject all". Keyed by `id`, not `ean` — see PendingResult's docblock for why more than one result can share an ean. */
  function dismissResult(id: number) {
    setResults((prev) => prev.filter((r) => r.id !== id))
  }

  const activeLibrary = libraries.find((l) => l.id === libraryId)

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('capture.title')}</h1>
        <p className="hint">{t('capture.subtitle')}</p>
      </header>

      {error && <p role="alert">{error}</p>}

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
            ref={codeInputRef}
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

      {(results.length > 0 || pendingLookups.length > 0) && (
        <section className="panel-card">
          <h2>{t('capture.resultsTitle', { count: results.length })}</h2>

          <ul className="capture-results">
            {/* GitHub issue #93: rendered above the real results below, same "newest first" order results.length itself already prepends new entries in. */}
            {pendingLookups.map((pending) => (
              <li key={pending.id} className="capture-result capture-result--pending">
                <div className="capture-result__header">
                  <Spinner />
                  <span className="capture-result__ean">{pending.ean}</span>
                  <span className="hint">{t('capture.searching')}</span>
                </div>
              </li>
            ))}

            {results.map((result) => (
              <li key={result.id} className="capture-result">
                <div className="capture-result__header">
                  <span className="capture-result__ean">{result.ean}</span>
                  {result.status === 'duplicate' && <span className="warning warning--danger">{t('capture.duplicate')}</span>}
                  {result.status !== 'candidates' && (
                    <button
                      type="button"
                      className="capture-result__dismiss"
                      aria-label={t('capture.dismiss')}
                      onClick={() => dismissResult(result.id)}
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
                    groupId={result.id}
                    ean={result.ean}
                    mediaType={result.library.media_type}
                    merged={result.merged}
                    onConfirm={(attributes, coverUrl, providerKeys) => void confirmMerged(result, attributes, coverUrl, providerKeys)}
                    onReject={() => dismissResult(result.id)}
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
