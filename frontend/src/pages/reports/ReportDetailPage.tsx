import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { type LibraryRef, type MediaItem, formatDuration } from '../libraries/mediaItemFields'
import { MediaItemDetailDialog } from '../libraries/MediaItemDetailDialog'
import { formatProviderKey } from '../capture/MetadataMergeReview'
import {
  REPORTS,
  type CaptureSourceResponse,
  type DataQualityRow,
  type DuplicateGroup,
  type ReportItem,
  type ReportKey,
  type TopListRow,
  type TopLists,
} from './reportTypes'

/** A report item's identity columns (title/EAN/library/media type), shared by every table below — the report-specific extra column is passed as `children` per row via `extraHeader`/`renderExtra`. GitHub issue #102: every row opens `onSelect`'s row in MediaItemDetailDialog — same `media-item-table__row`/`__title-button` classes (and click/keyboard pattern) LibraryDetailPage's and SearchPage's own item tables already use, so the affordance looks and behaves identically everywhere in the app. */
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

  if (rows.length === 0) return null

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

/** A compact top-N ranking (GitHub issue #74's "Top-Listen") — a numbered list rather than ItemsTable's full table, since there's only ever one value column and up to eight of these render side by side. GitHub issue #102: same click-to-open behavior as ItemsTable above. */
function TopList({ title, rows, formatValue, onSelect }: { title: string; rows: TopListRow[]; formatValue: (value: number | string) => string; onSelect: (row: TopListRow) => void }) {
  const { t } = useTranslation()

  if (rows.length === 0) return null

  return (
    <div className="report-top-list">
      <h4>{title}</h4>
      <ol>
        {rows.map((row) => (
          <li key={row.id} className="media-item-table__row" onClick={() => onSelect(row)}>
            <button type="button" className="media-item-table__title-button report-top-list__title" onClick={(e) => { e.stopPropagation(); onSelect(row) }}>
              {row.title}
            </button>
            <span className="report-top-list__value">{formatValue(row.value)}</span>
            <span className="hint">
              {row.library_name} · {t(`libraries.mediaType.${row.media_type}`)}
            </span>
          </li>
        ))}
      </ol>
    </div>
  )
}

function formatPrice(row: { price: number | string | null; currency: string | null }): string {
  if (row.price === null) return '—'
  return row.currency ? `${row.price} ${row.currency}` : String(row.price)
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
  const { t } = useTranslation()
  const { key } = useParams<{ key: string }>()
  const meta = REPORTS.find((r) => r.key === key)
  const [loading, setLoading] = useState(true)
  const [duplicates, setDuplicates] = useState<DuplicateGroup[] | null>(null)
  const [dataQuality, setDataQuality] = useState<DataQualityRow[] | null>(null)
  const [recentAdditions, setRecentAdditions] = useState<ReportItem[] | null>(null)
  const [topLists, setTopLists] = useState<TopLists | null>(null)
  const [captureSource, setCaptureSource] = useState<CaptureSourceResponse | null>(null)

  // Every library visible to this user (GET /libraries) — both the source
  // of a clicked row's full LibraryRef (id/name/media_type/owner; a report
  // row itself only has library_id/library_name/media_type, see
  // ReportItem's docblock) and MediaItemDetailDialog's own "move to
  // another library" target list, same as SearchPage.tsx.
  const [libraries, setLibraries] = useState<LibraryRef[]>([])
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [selectedLibrary, setSelectedLibrary] = useState<LibraryRef | null>(null)

  useEffect(() => {
    apiClient.get<LibraryRef[]>('/libraries').then(({ data }) => setLibraries(data))
  }, [])

  async function loadReport(reportKey: ReportKey) {
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
      </header>

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
                  <ItemsTable rows={group.items} extraHeader={t('mediaItem.fields.price')} renderExtra={formatPrice} onSelect={(row) => void openItem(row)} />
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
              <TopList title={t('reports.topLists.mostExpensive')} rows={topLists.most_expensive} formatValue={(v) => formatPrice({ price: v, currency: null })} onSelect={(row) => void openItem(row)} />
              <TopList title={t('reports.topLists.cheapest')} rows={topLists.cheapest} formatValue={(v) => formatPrice({ price: v, currency: null })} onSelect={(row) => void openItem(row)} />
              <TopList title={t('reports.topLists.mostPages')} rows={topLists.most_pages} formatValue={(v) => String(v)} onSelect={(row) => void openItem(row)} />
              <TopList title={t('reports.topLists.longestCdRuntime')} rows={topLists.longest_cd_runtime} formatValue={(v) => formatDuration(Number(v))} onSelect={(row) => void openItem(row)} />
              <TopList title={t('reports.topLists.shortestCdRuntime')} rows={topLists.shortest_cd_runtime} formatValue={(v) => formatDuration(Number(v))} onSelect={(row) => void openItem(row)} />
              <TopList
                title={t('reports.topLists.longestDvdRuntime')}
                rows={topLists.longest_dvd_runtime}
                formatValue={(v) => t('reports.topLists.minutes', { count: Number(v) })}
                onSelect={(row) => void openItem(row)}
              />
              <TopList
                title={t('reports.topLists.shortestDvdRuntime')}
                rows={topLists.shortest_dvd_runtime}
                formatValue={(v) => t('reports.topLists.minutes', { count: Number(v) })}
                onSelect={(row) => void openItem(row)}
              />
              <TopList title={t('reports.topLists.highestDiscCount')} rows={topLists.highest_disc_count} formatValue={(v) => String(v)} onSelect={(row) => void openItem(row)} />
            </div>
          )}

          {meta.key === 'capture-source' && captureSource && (
            <>
              <p className="library-detail__meta hint">
                {Object.entries(captureSource.by_capture_method).map(([method, count]) => (
                  <span key={method}>
                    {t(`reports.captureSource.method.${method}`)}: {count}
                  </span>
                ))}
              </p>
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
