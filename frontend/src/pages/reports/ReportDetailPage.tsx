import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { type LibraryRef, type MediaItem, formatDuration, formatPrice } from '../libraries/mediaItemFields'
import { MediaItemDetailDialog } from '../libraries/MediaItemDetailDialog'
import { formatProviderKey } from '../capture/MetadataMergeReview'
import {
  REPORTS,
  type CaptureSourceResponse,
  type DataQualityRow,
  type DuplicateGroup,
  type ReportItem,
  type ReportKey,
  type SharingRow,
  type TopListRow,
  type TopLists,
  type UserActivityRow,
} from './reportTypes'

/**
 * A report item's identity columns (title/EAN/library/media type), shared by
 * every table below — the report-specific extra column is passed as
 * `children` per row via `extraHeader`/`renderExtra`. GitHub issue #102:
 * every row opens `onSelect`'s row in MediaItemDetailDialog — same
 * `media-item-table__row`/`__title-button` classes (and click/keyboard
 * pattern) LibraryDetailPage's and SearchPage's own item tables already
 * use, so the affordance looks and behaves identically everywhere in the
 * app.
 *
 * GitHub issue #106: shows `reports.none` itself when `rows` is empty,
 * rather than rendering nothing — the PDF export's equivalent
 * (resources/views/pdf/partials/items-table.blade.php) already did this
 * from the start, and having the check live here once means every call
 * site (including top-lists' 8 rankings and capture-source, which used to
 * have no empty-state handling at all) gets it automatically instead of
 * needing its own emptiness check.
 */
function ItemsTable<T extends ReportItem>({
  rows,
  extraHeader,
  renderExtra,
  onSelect,
}: {
  rows: T[]
  extraHeader?: string
  renderExtra?: (row: T) => React.ReactNode
  onSelect: (row: T) => void
}) {
  const { t } = useTranslation()

  if (rows.length === 0) return <p className="hint">{t('reports.none')}</p>

  return (
    <table>
      <thead>
        <tr>
          <th>{t('mediaItem.fields.title')}</th>
          <th>{t('mediaItem.fields.ean')}</th>
          <th>{t('reports.library')}</th>
          {extraHeader && <th>{extraHeader}</th>}
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.id} className="media-item-table__row" onClick={() => onSelect(row)}>
            <td>
              <button type="button" className="media-item-table__title-button" onClick={(e) => { e.stopPropagation(); onSelect(row) }}>
                {row.title}
              </button>
            </td>
            <td>{row.ean}</td>
            <td>
              {row.library_name} <span className="media-type-badge">{t(`libraries.mediaType.${row.media_type}`)}</span>
            </td>
            {renderExtra && <td>{renderExtra(row)}</td>}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

/**
 * A top-N ranking (GitHub issue #74's "Top-Listen") — GitHub issue #104: a
 * thin wrapper around ItemsTable rather than the bespoke `<ol>` this used
 * to be, whose contents (title button, value, library) had no CSS at all
 * and just ran together as plain inline text. `TopListRow` already extends
 * `ReportItem`, so this is exactly ItemsTable with the ranking's own metric
 * as the extra column — same table formatting (and click-to-open, GitHub
 * issue #102) as every other report instead of a one-off layout.
 *
 * GitHub issue #106: no longer hides itself (`return null`) when `rows` is
 * empty — the heading now always shows, with ItemsTable's own `reports.none`
 * filling in for the missing table, matching the PDF export's
 * pdf.reports.top-lists.blade.php (which always renders every ranking's
 * `<h2>`, unconditionally). An empty ranking used to just silently vanish,
 * indistinguishable from "this ranking doesn't exist" rather than "nothing
 * currently qualifies for it".
 *
 * `formatValue` receives the whole row, not just `row.value` (GitHub issue
 * #107) — the price-based rankings (most expensive/cheapest) need `row`'s
 * own `currency` too, to format via the shared formatPrice() the same way
 * every other price display in the app now does; a ranking whose metric
 * isn't a price just ignores the rest of the row and formats `row.value`
 * directly, same as before.
 */
function TopList({ title, rows, extraHeader, formatValue, onSelect }: { title: string; rows: TopListRow[]; extraHeader: string; formatValue: (row: TopListRow) => string; onSelect: (row: TopListRow) => void }) {
  return (
    <div className="report-top-list">
      <h4>{title}</h4>
      <ItemsTable rows={rows} extraHeader={extraHeader} renderExtra={formatValue} onSelect={onSelect} />
    </div>
  )
}

/**
 * One report's actual table (GitHub issue #74; split out of the combined
 * overview page into its own route by GitHub issue #101, so a user picks a
 * report from ReportsPage.tsx's list first instead of loading and scrolling
 * past all five every time). Only the one report named by the `:key` route
 * param is fetched — unlike the old combined page's Promise.all of all
 * five reports up front, most of which went unread on any given visit.
 *
 * GitHub issue #102: every row is clickable, opening MediaItemDetailDialog
 * right here (same in-place pattern SearchPage.tsx uses, GitHub issue
 * #100) instead of sending the user off to go find the item in its owning
 * library. Unlike SearchPage's full-model search hits, a report row
 * (ReportsService::itemSummary()) only carries a handful of summary
 * columns, so the full item is fetched lazily on click rather than eagerly
 * for every row of every report.
 */
export function ReportDetailPage() {
  const { t, i18n } = useTranslation()
  const { key } = useParams<{ key: string }>()
  const meta = REPORTS.find((r) => r.key === key)
  const [loading, setLoading] = useState(true)
  const [duplicates, setDuplicates] = useState<DuplicateGroup[] | null>(null)
  const [dataQuality, setDataQuality] = useState<DataQualityRow[] | null>(null)
  const [recentAdditions, setRecentAdditions] = useState<ReportItem[] | null>(null)
  const [topLists, setTopLists] = useState<TopLists | null>(null)
  const [captureSource, setCaptureSource] = useState<CaptureSourceResponse | null>(null)
  const [sharing, setSharing] = useState<SharingRow[] | null>(null)
  const [userActivity, setUserActivity] = useState<UserActivityRow[] | null>(null)
  // GitHub issue #105 — previously missing entirely, same gap SearchPage.tsx
  // already got fixed for: a failed request left every report's state at
  // its initial `null`, rendering as if the report were simply empty
  // (reports.none) rather than as a genuine load failure.
  const [error, setError] = useState<string | null>(null)

  // Every library visible to this user (GET /libraries) — both the source
  // of a clicked row's full LibraryRef (id/name/media_type/owner; a report
  // row itself only has library_id/library_name/media_type, see
  // ReportItem's docblock) and MediaItemDetailDialog's own "move to
  // another library" target list, same as SearchPage.tsx.
  const [libraries, setLibraries] = useState<LibraryRef[]>([])
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [selectedLibrary, setSelectedLibrary] = useState<LibraryRef | null>(null)

  // GitHub issue #106: skipped for 'sharing'/'user-activity' — their rows
  // are libraries/users, not media items (see SharingRow/UserActivityRow's
  // own docblocks in reportTypes.ts), so neither ever calls openItem()
  // below, and `libraries` would just sit unused. Every other report needs
  // it, so still fetched eagerly rather than only once a row is actually
  // clicked — same reasoning MediaItemDetailDialog's "move to library"
  // dropdown already fetches it up front elsewhere.
  useEffect(() => {
    if (key === 'sharing' || key === 'user-activity') return
    apiClient.get<LibraryRef[]>('/libraries').then(({ data }) => setLibraries(data))
  }, [key])

  async function loadReport(reportKey: ReportKey) {
    setError(null)
    try {
      await loadReportUnsafe(reportKey)
    } catch (err) {
      console.error('Failed to load report:', err)
      setError(t('reports.error'))
    }
  }

  async function loadReportUnsafe(reportKey: ReportKey) {
    switch (reportKey) {
      case 'duplicates': {
        const { data } = await apiClient.get<DuplicateGroup[]>('/reports/duplicates')
        setDuplicates(data)
        break
      }
      case 'data-quality': {
        const { data } = await apiClient.get<DataQualityRow[]>('/reports/data-quality')
        setDataQuality(data)
        break
      }
      case 'recent-additions': {
        const { data } = await apiClient.get<ReportItem[]>('/reports/recent-additions')
        setRecentAdditions(data)
        break
      }
      case 'top-lists': {
        const { data } = await apiClient.get<TopLists>('/reports/top-lists')
        setTopLists(data)
        break
      }
      case 'capture-source': {
        const { data } = await apiClient.get<CaptureSourceResponse>('/reports/capture-source')
        setCaptureSource(data)
        break
      }
      case 'sharing': {
        const { data } = await apiClient.get<SharingRow[]>('/reports/sharing')
        setSharing(data)
        break
      }
      case 'user-activity': {
        const { data } = await apiClient.get<UserActivityRow[]>('/reports/user-activity')
        setUserActivity(data)
        break
      }
    }
  }

  useEffect(() => {
    if (!REPORTS.some((r) => r.key === key)) return
    setLoading(true)
    void loadReport(key as ReportKey).finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  /**
   * A report is a computed/aggregated view (a grouping, a ranking, a
   * "missing fields" filter, ...), not a plain item list like
   * LibraryDetailPage's or SearchPage's — editing an item's price could
   * change its rank in a top list, clearing its last missing field could
   * drop it from the data-quality report entirely, and so on. Patching
   * `duplicates`/`dataQuality`/... client-side to reflect an edit would
   * mean reimplementing each report's server-side logic here just to stay
   * correct, so a mutation instead just re-fetches the current report from
   * the server, same "reload rather than splice" precedent
   * CreateMediaItemDialog's onCreated docblock already documents.
   */
  function refresh() {
    if (meta) void loadReport(meta.key)
  }

  async function openItem(row: ReportItem) {
    const library = libraries.find((l) => l.id === row.library_id)
    if (!library) return
    const { data: item } = await apiClient.get<MediaItem>(`/libraries/${row.library_id}/items/${row.id}`)
    setSelectedItem(item)
    setSelectedLibrary(library)
  }

  function closeDialog() {
    setSelectedItem(null)
    setSelectedLibrary(null)
  }

  if (!meta) {
    return (
      <div className="panel-page">
        <p>
          <Link to="/reports">← {t('reports.title')}</Link>
        </p>
        <p className="hint">{t('reports.notFound')}</p>
      </div>
    )
  }

  return (
    <div className="panel-page">
      <p>
        <Link to="/reports">← {t('reports.title')}</Link>
      </p>

      <header className="panel-page__header">
        <h1>{t(meta.titleKey)}</h1>
        <p className="hint">{t(meta.hintKey)}</p>
        {/* GitHub issue #87: a fresh server-side render (PdfExportService),
            not a client-side snapshot of what's currently loaded — a plain
            navigation rather than an apiClient request, same
            window.location.href pattern BackupsPage.tsx's download button
            already uses for a GET file download, so the browser's normal
            Content-Disposition handling (and the already-authenticated
            session cookie) does the rest without any Blob/object-URL
            plumbing. */}
        <button
          type="button"
          onClick={() => {
            window.location.href = `${apiClient.defaults.baseURL}/reports/${meta.key}/export/pdf`
          }}
        >
          {t('reports.exportPdf')}
        </button>
      </header>

      {error && <p role="alert">{error}</p>}

      {loading ? (
        <p className="hint">…</p>
      ) : (
        <section className="panel-card">
          {meta.key === 'duplicates' &&
            (duplicates && duplicates.length > 0 ? (
              duplicates.map((group) => (
                <div key={`${group.media_type}-${group.ean}`} className="report-duplicate-group">
                  <h4>
                    {group.ean} <span className="media-type-badge">{t(`libraries.mediaType.${group.media_type}`)}</span>
                  </h4>
                  <ItemsTable rows={group.items} extraHeader={t('mediaItem.fields.price')} renderExtra={(row) => formatPrice(row.price, row.currency, i18n.language)} onSelect={(row) => void openItem(row)} />
                </div>
              ))
            ) : (
              <p className="hint">{t('reports.none')}</p>
            ))}

          {meta.key === 'data-quality' &&
            (dataQuality && dataQuality.length > 0 ? (
              <ItemsTable
                rows={dataQuality}
                extraHeader={t('reports.dataQuality.missingFields')}
                renderExtra={(row) => row.missing_fields.map((field) => t(`mediaItem.fields.${field}`)).join(', ')}
                onSelect={(row) => void openItem(row)}
              />
            ) : (
              <p className="hint">{t('reports.none')}</p>
            ))}

          {meta.key === 'recent-additions' &&
            (recentAdditions && recentAdditions.length > 0 ? (
              <ItemsTable
                rows={recentAdditions}
                extraHeader={t('reports.recentAdditions.addedAt')}
                renderExtra={(row) => (row.created_at ? new Date(row.created_at).toLocaleDateString() : '—')}
                onSelect={(row) => void openItem(row)}
              />
            ) : (
              <p className="hint">{t('reports.none')}</p>
            ))}

          {meta.key === 'top-lists' && topLists && (
            <div className="report-top-lists">
              <TopList
                title={t('reports.topLists.mostExpensive')}
                rows={topLists.most_expensive}
                extraHeader={t('mediaItem.fields.price')}
                formatValue={(row) => formatPrice(row.value, row.currency, i18n.language)}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.cheapest')}
                rows={topLists.cheapest}
                extraHeader={t('mediaItem.fields.price')}
                formatValue={(row) => formatPrice(row.value, row.currency, i18n.language)}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.mostPages')}
                rows={topLists.most_pages}
                extraHeader={t('mediaItem.fields.page_count')}
                formatValue={(row) => String(row.value)}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.longestCdRuntime')}
                rows={topLists.longest_cd_runtime}
                extraHeader={t('mediaItem.runtime')}
                formatValue={(row) => formatDuration(Number(row.value))}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.shortestCdRuntime')}
                rows={topLists.shortest_cd_runtime}
                extraHeader={t('mediaItem.runtime')}
                formatValue={(row) => formatDuration(Number(row.value))}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.longestDvdRuntime')}
                rows={topLists.longest_dvd_runtime}
                extraHeader={t('mediaItem.fields.runtime_minutes')}
                formatValue={(row) => t('reports.topLists.minutes', { count: Number(row.value) })}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.shortestDvdRuntime')}
                rows={topLists.shortest_dvd_runtime}
                extraHeader={t('mediaItem.fields.runtime_minutes')}
                formatValue={(row) => t('reports.topLists.minutes', { count: Number(row.value) })}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.highestDiscCount')}
                rows={topLists.highest_disc_count}
                extraHeader={t('mediaItem.fields.disc_count')}
                formatValue={(row) => String(row.value)}
                onSelect={(row) => void openItem(row)}
              />
            </div>
          )}

          {meta.key === 'capture-source' && captureSource && (
            <>
              {/* GitHub issue #106: guarded the same way by_metadata_provider below already was — empty whenever captureSource.items itself is (no items at all), which used to render as a visible-but-blank paragraph instead of nothing. */}
              {Object.keys(captureSource.by_capture_method).length > 0 && (
                <p className="library-detail__meta hint">
                  {Object.entries(captureSource.by_capture_method).map(([method, count]) => (
                    <span key={method}>
                      {t(`reports.captureSource.method.${method}`)}: {count}
                    </span>
                  ))}
                </p>
              )}
              {Object.keys(captureSource.by_metadata_provider).length > 0 && (
                <p className="library-detail__meta hint">
                  {Object.entries(captureSource.by_metadata_provider).map(([provider, count]) => (
                    <span key={provider}>
                      {formatProviderKey(provider)}: {count}
                    </span>
                  ))}
                </p>
              )}
              <ItemsTable
                rows={captureSource.items}
                extraHeader={t('reports.captureSource.title')}
                renderExtra={(row) => (
                  <>
                    {t(`reports.captureSource.method.${row.capture_method ?? 'unknown'}`)}
                    {row.metadata_provider && ` (${row.metadata_provider.split(',').map(formatProviderKey).join(', ')})`}
                  </>
                )}
                onSelect={(row) => void openItem(row)}
              />
            </>
          )}

          {/* GitHub issue #103: moved here from StatisticsPage.tsx — rows are libraries, not media items, so unlike every table above there's no single item to open on click. */}
          {meta.key === 'sharing' &&
            (sharing && sharing.length > 0 ? (
              <table>
                <thead>
                  <tr>
                    <th>{t('libraries.title')}</th>
                    <th>{t('reports.sharing.sharedWith')}</th>
                  </tr>
                </thead>
                <tbody>
                  {sharing.map((row) => (
                    <tr key={row.library_id}>
                      <td>
                        {row.library_name} <span className="media-type-badge">{t(`libraries.mediaType.${row.media_type}`)}</span>
                      </td>
                      <td>
                        {row.is_shared ? (
                          row.shares
                            .map((share) => {
                              const who = share.scope === 'user' ? (share.user_name ?? '?') : t(`reports.sharing.scope.${share.scope}`)

                              return `${who} (${t(`reports.sharing.accessLevel.${share.access_level}`)})`
                            })
                            .join(', ')
                        ) : (
                          <span className="hint">{t('reports.sharing.notShared')}</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="hint">{t('reports.none')}</p>
            ))}

          {/* GitHub issue #103: moved here from StatisticsPage.tsx — rows are users, not media items, same reasoning as 'sharing' above. */}
          {meta.key === 'user-activity' &&
            (userActivity && userActivity.length > 0 ? (
              <table>
                <thead>
                  <tr>
                    <th>{t('reports.userActivity.user')}</th>
                    <th>{t('reports.itemCount')}</th>
                    <th>{t('reports.userActivity.lastCaptured')}</th>
                  </tr>
                </thead>
                <tbody>
                  {userActivity.map((row) => (
                    <tr key={row.user_id ?? 'unknown'}>
                      <td>{row.user_name ?? t('reports.userActivity.unknownUser')}</td>
                      <td>{row.item_count}</td>
                      <td>{row.last_captured_at ? new Date(row.last_captured_at).toLocaleDateString() : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="hint">{t('reports.none')}</p>
            ))}
        </section>
      )}

      <MediaItemDetailDialog
        library={selectedLibrary ?? { id: 0, name: '', media_type: 'book', owner: { id: 0, name: '' } }}
        item={selectedItem}
        libraries={libraries}
        onClose={closeDialog}
        onUpdated={(updated) => {
          setSelectedItem(updated)
          refresh()
        }}
        onDeleted={() => {
          closeDialog()
          refresh()
        }}
        onMoved={() => {
          closeDialog()
          refresh()
        }}
      />
    </div>
  )
}
