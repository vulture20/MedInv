import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { formatDuration } from '../libraries/mediaItemFields'
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

/** A report item's identity columns (title/EAN/library/media type), shared by every table below — the report-specific extra column is passed as `children` per row via `extraHeader`/`renderExtra`. */
function ItemsTable<T extends ReportItem>({ rows, extraHeader, renderExtra }: { rows: T[]; extraHeader?: string; renderExtra?: (row: T) => React.ReactNode }) {
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
          <tr key={row.id}>
            <td>{row.title}</td>
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

/** A compact top-N ranking (GitHub issue #74's "Top-Listen") — a numbered list rather than ItemsTable's full table, since there's only ever one value column and up to eight of these render side by side. */
function TopList({ title, rows, formatValue }: { title: string; rows: TopListRow[]; formatValue: (value: number | string) => string }) {
  const { t } = useTranslation()

  if (rows.length === 0) return null

  return (
    <div className="report-top-list">
      <h4>{title}</h4>
      <ol>
        {rows.map((row) => (
          <li key={row.id}>
            <span className="report-top-list__title">{row.title}</span>
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

  useEffect(() => {
    if (!REPORTS.some((r) => r.key === key)) return
    setLoading(true)

    async function run() {
      switch (key as ReportKey) {
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

    void run().finally(() => setLoading(false))
  }, [key])

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
                  <ItemsTable rows={group.items} extraHeader={t('mediaItem.fields.price')} renderExtra={formatPrice} />
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
              />
            ) : (
              <p className="hint">{t('reports.none')}</p>
            ))}

          {meta.key === 'top-lists' && topLists && (
            <div className="report-top-lists">
              <TopList title={t('reports.topLists.mostExpensive')} rows={topLists.most_expensive} formatValue={(v) => formatPrice({ price: v, currency: null })} />
              <TopList title={t('reports.topLists.cheapest')} rows={topLists.cheapest} formatValue={(v) => formatPrice({ price: v, currency: null })} />
              <TopList title={t('reports.topLists.mostPages')} rows={topLists.most_pages} formatValue={(v) => String(v)} />
              <TopList title={t('reports.topLists.longestCdRuntime')} rows={topLists.longest_cd_runtime} formatValue={(v) => formatDuration(Number(v))} />
              <TopList title={t('reports.topLists.shortestCdRuntime')} rows={topLists.shortest_cd_runtime} formatValue={(v) => formatDuration(Number(v))} />
              <TopList
                title={t('reports.topLists.longestDvdRuntime')}
                rows={topLists.longest_dvd_runtime}
                formatValue={(v) => t('reports.topLists.minutes', { count: Number(v) })}
              />
              <TopList
                title={t('reports.topLists.shortestDvdRuntime')}
                rows={topLists.shortest_dvd_runtime}
                formatValue={(v) => t('reports.topLists.minutes', { count: Number(v) })}
              />
              <TopList title={t('reports.topLists.highestDiscCount')} rows={topLists.highest_disc_count} formatValue={(v) => String(v)} />
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
              />
            </>
          )}
        </section>
      )}
    </div>
  )
}
