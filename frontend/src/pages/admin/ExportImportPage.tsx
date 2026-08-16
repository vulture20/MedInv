import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError, describeBlobError } from './adminErrors'
import type { LibraryRef } from '../libraries/mediaItemFields'

interface ImportResult {
  created: string[]
  merged: string[]
  overwritten: string[]
  skipped: string[]
}

/**
 * Instance-to-instance export/import of individual, several, or all
 * libraries (briefing 9.1). Admin-only (routes/api.php), since
 * ExportImportController::export() deliberately bypasses per-library share
 * checks (GET /libraries already returns every library for an admin via
 * LibraryAccessService::visibleLibrariesQuery(), so no separate fetch is
 * needed here).
 *
 * The conflict-resolution UI mirrors BackupsPage's restore form on purpose:
 * BackupController::restore() and this page's import both end up calling
 * ExportImportService::importLibraries() (see its docblock) with the same
 * `__default__` all-conflicts sentinel, since neither this page nor
 * BackupsPage has a way to know ahead of time which library names inside an
 * arbitrary export/backup file will actually collide with this instance's.
 * Deliberately missing here, unlike BackupsPage: a "restore system settings
 * and user accounts" checkbox — a plain library export never contains user
 * accounts at all (ExportImportService::exportLibraries() only embeds those
 * when called with $includeUsers, and export() never sets it), so offering
 * that option here would promise something it can't do.
 */
export function ExportImportPage() {
  const { t } = useTranslation()
  const [libraries, setLibraries] = useState<LibraryRef[]>([])
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const [file, setFile] = useState<File | null>(null)
  const [overwriteExisting, setOverwriteExisting] = useState(false)
  const [importing, setImporting] = useState(false)
  const [importResult, setImportResult] = useState<string | null>(null)
  const [importError, setImportError] = useState<string | null>(null)

  useEffect(() => {
    void apiClient.get<LibraryRef[]>('/libraries').then(({ data }) => setLibraries(data))
  }, [])

  function toggle(id: number) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function runExport() {
    setExportError(null)
    setExporting(true)
    try {
      const response = await apiClient.post(
        '/admin/export',
        selected.size > 0 ? { library_ids: [...selected] } : {},
        { responseType: 'blob' },
      )
      // Content-Disposition carries the server-computed, timezone-aware
      // filename (ExportImportController::export(), GitHub issue #31) —
      // config/cors.php explicitly exposes it for cross-origin dev, since a
      // POST download can't rely on the browser handling it natively the
      // way a plain <a href> GET (BackupsPage's download link) does.
      const disposition = response.headers['content-disposition'] as string | undefined
      const filename = /filename="([^"]+)"/.exec(disposition ?? '')?.[1] ?? 'medinv-export.zip'
      const url = URL.createObjectURL(response.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      a.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      setExportError(await describeBlobError(err, t))
    } finally {
      setExporting(false)
    }
  }

  async function runImport(e: React.FormEvent) {
    e.preventDefault()
    if (!file) return
    setImportError(null)
    setImportResult(null)
    setImporting(true)
    try {
      const form = new FormData()
      form.append('file', file)
      if (overwriteExisting) form.append('conflict_resolutions[__default__]', 'overwrite')
      const { data } = await apiClient.post<ImportResult>('/admin/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setImportResult(
        t('admin.exportImportPage.importSuccess', {
          created: data.created.length,
          overwritten: data.overwritten.length,
          merged: data.merged.length,
          skipped: data.skipped.length,
        }),
      )
      setFile(null)
    } catch (err) {
      setImportError(describeError(err, t))
    } finally {
      setImporting(false)
    }
  }

  return (
    <>
      <section>
        <h2>{t('admin.exportImportPage.exportTitle')}</h2>
        <ul className="export-library-list">
          {libraries.map((lib) => (
            <li key={lib.id}>
              <label>
                <input type="checkbox" checked={selected.has(lib.id)} onChange={() => toggle(lib.id)} />
                {lib.name} — {t(`libraries.mediaType.${lib.media_type}`)} ({lib.owner.name})
              </label>
            </li>
          ))}
        </ul>
        <p className="hint">{t('admin.exportImportPage.exportHint')}</p>
        <button onClick={() => void runExport()} disabled={exporting}>
          {t('admin.exportImportPage.exportSubmit')}
        </button>
        {exportError && <p role="alert">{exportError}</p>}
      </section>

      <section>
        <h2>{t('admin.exportImportPage.importTitle')}</h2>
        <form onSubmit={runImport}>
          <label>
            {t('admin.exportImportPage.importFile')}
            <input
              type="file"
              accept=".zip,application/zip"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              required
            />
          </label>
          <label>
            <input
              type="checkbox"
              checked={overwriteExisting}
              onChange={(e) => setOverwriteExisting(e.target.checked)}
            />
            {t('admin.backupRestore.overwriteExisting')}
          </label>
          <button type="submit" disabled={importing || !file}>
            {t('admin.exportImportPage.importSubmit')}
          </button>
        </form>
        {importResult && <p role="status">{importResult}</p>}
        {importError && <p role="alert">{importError}</p>}
      </section>
    </>
  )
}
