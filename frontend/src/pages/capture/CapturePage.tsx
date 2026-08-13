import { lazy, Suspense, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'

// The barcode decoder (@zxing/library) is a heavy dependency (~800KB) that
// most captures never touch — hardware-scanner and manual entry cover the
// common case. Loaded on demand only once the camera is actually opened,
// instead of adding that weight to every visit of this page.
const CameraScanner = lazy(() => import('./CameraScanner').then((m) => ({ default: m.CameraScanner })))

interface Library {
  id: number
  name: string
  media_type: 'book' | 'cd' | 'dvd_bluray'
}

interface MetadataCandidate {
  provider_key: string
  source_id: string
  attributes: Record<string, unknown>
  cover_urls: string[]
}

interface ScanResult {
  status: 'duplicate' | 'no_match' | 'candidates'
  ean: string
  candidates?: MetadataCandidate[]
}

/**
 * Bulk capture (briefing 7.2). The hardware-scanner, manual-entry and
 * camera paths all funnel into `scanCode()` — a hardware scanner types the
 * code into `codeInput` followed by Enter (its native behavior), which
 * submits the form exactly like a manually typed EAN would, and
 * `CameraScanner` calls the exact same handler with its decoded result.
 */
export function CapturePage() {
  const { t } = useTranslation()
  const [libraries, setLibraries] = useState<Library[]>([])
  const [libraryId, setLibraryId] = useState<number | null>(null)
  const [codeInput, setCodeInput] = useState('')
  const [results, setResults] = useState<ScanResult[]>([])
  const [file, setFile] = useState<File | null>(null)
  const [cameraOpen, setCameraOpen] = useState(false)

  useEffect(() => {
    void apiClient.get<Library[]>('/libraries').then(({ data }) => {
      setLibraries(data)
      if (data.length) setLibraryId(data[0].id)
    })
  }, [])

  async function scanCode(code: string) {
    if (!libraryId || !code.trim()) return
    const { data } = await apiClient.post<ScanResult>(`/libraries/${libraryId}/capture/scan`, {
      ean: code.trim(),
    })
    setResults((prev) => [data, ...prev])
  }

  async function submitCode(e: React.FormEvent) {
    e.preventDefault()
    await scanCode(codeInput)
    setCodeInput('')
  }

  async function submitTextFile(e: React.FormEvent) {
    e.preventDefault()
    if (!libraryId || !file) return
    const form = new FormData()
    form.append('file', file)
    const { data } = await apiClient.post<ScanResult[]>(`/libraries/${libraryId}/capture/textfile`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    setResults((prev) => [...data, ...prev])
    setFile(null)
  }

  async function importCandidate(candidate: MetadataCandidate) {
    if (!libraryId) return
    await apiClient.post(`/libraries/${libraryId}/metadata/import`, {
      attributes: candidate.attributes,
      cover_url: candidate.cover_urls[0],
    })
    setResults((prev) => prev.filter((r) => r.ean !== candidate.attributes.ean))
  }

  return (
    <div>
      <h1>{t('capture.title')}</h1>

      <label>
        {t('libraries.title')}
        <select value={libraryId ?? ''} onChange={(e) => setLibraryId(Number(e.target.value))}>
          {libraries.map((lib) => (
            <option key={lib.id} value={lib.id}>
              {lib.name}
            </option>
          ))}
        </select>
      </label>

      {/* Hardware scanner + manual entry share this one input (7.2). */}
      <form onSubmit={submitCode}>
        <label>
          {t('capture.scan')}
          <input
            value={codeInput}
            onChange={(e) => setCodeInput(e.target.value)}
            placeholder={t('capture.eanIsbnPlaceholder')}
            autoFocus
          />
        </label>
        <button type="submit">{t('capture.scan')}</button>
      </form>

      {cameraOpen ? (
        <Suspense fallback={<p>…</p>}>
          <CameraScanner onDecode={(code) => void scanCode(code)} onClose={() => setCameraOpen(false)} />
        </Suspense>
      ) : (
        <button type="button" onClick={() => setCameraOpen(true)}>
          {t('capture.cameraScan')}
        </button>
      )}

      <form onSubmit={submitTextFile}>
        <label>
          {t('capture.textFileImport')}
          <input type="file" accept=".txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </label>
        <button type="submit" disabled={!file}>
          {t('capture.textFileImport')}
        </button>
      </form>

      <ul className="capture-results">
        {results.map((result) => (
          <li key={result.ean}>
            <strong>{result.ean}</strong>{' '}
            {result.status === 'duplicate' && <span className="warning">{t('capture.duplicate')}</span>}
            {result.status === 'no_match' && <span>{t('capture.noMatch')}</span>}
            {result.status === 'candidates' && (
              <div>
                <p>{t('capture.chooseCandidate')}</p>
                {result.candidates?.map((c) => (
                  <button key={`${c.provider_key}:${c.source_id}`} onClick={() => void importCandidate(c)}>
                    {String(c.attributes.title ?? c.source_id)} ({c.provider_key})
                  </button>
                ))}
                <button onClick={() => setResults((prev) => prev.filter((r) => r.ean !== result.ean))}>
                  {t('capture.rejectAll')}
                </button>
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
