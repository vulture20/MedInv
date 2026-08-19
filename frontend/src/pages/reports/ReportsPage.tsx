import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { formatDuration, type MediaType } from '../libraries/mediaItemFields'
import { formatProviderKey } from '../capture/MetadataMergeReview'

/** One row shared by every report below — mirrors ReportsService::itemSummary(). */
interface ReportItem {
  id: number
  title: string
  ean: string
  library_id: number
  library_name: string
  media_type: MediaType
  price: number | string | null
  currency: string | null
  created_at: string | null
}

interface DuplicateGroup {
  ean: string
  media_type: MediaType
  items: ReportItem[]
}

interface DataQualityRow extends ReportItem {
  missing_fields: string[]
}

interface TopListRow extends ReportItem {
  value: number | string
}

interface TopLists {
  most_expensive: TopListRow[]
  cheapest: TopListRow[]
  most_pages: TopListRow[]
  longest_cd_runtime: TopListRow[]
  shortest_cd_runtime: TopListRow[]
  longest_dvd_runtime: TopListRow[]
  shortest_dvd_runtime: TopListRow[]
  highest_disc_count: TopListRow[]
}

interface CaptureSourceRow extends ReportItem {
  capture_method: 'scan' | 'manual' | null
  metadata_provider: string | null
  captured_by: string | null
}

interface CaptureSourceResponse {
  items: CaptureSourceRow[]
  by_capture_method: Record<string, number>
  by_metadata_provider: Record<string, number>
}

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
 * "Auswertungen" (GitHub issue #74): tables of concrete media items, unlike
 * StatisticsPage.tsx's charts/aggregated sums (see ReportsService's own
 * docblock for that split). Card layout matches StatisticsPage.tsx's
 * (.panel-page/.panel-card) — one card per report, consistent with every
 * other panel page in this app.
 */
export function ReportsPage() {
  const { t } = useTranslation()
  const [duplicates, setDuplicates] = useState<DuplicateGroup[]>([])
  const [dataQuality, setDataQuality] = useState<DataQualityRow[]>([])
  const [recentAdditions, setRecentAdditions] = useState<ReportItem[]>([])
  const [topLists, setTopLists] = useState<TopLists | null>(null)
  const [captureSource, setCaptureSource] = useState<CaptureSourceResponse | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    void Promise.all([
      apiClient.get<DuplicateGroup[]>('/reports/duplicates').then(({ data }) => setDuplicates(data)),
      apiClient.get<DataQualityRow[]>('/reports/data-quality').then(({ data }) => setDataQuality(data)),
      apiClient.get<ReportItem[]>('/reports/recent-additions').then(({ data }) => setRecentAdditions(data)),
      apiClient.get<TopLists>('/reports/top-lists').then(({ data }) => setTopLists(data)),
      apiClient.get<CaptureSourceResponse>('/reports/capture-source').then(({ data }) => setCaptureSource(data)),
    ]).finally(() => setLoading(false))
  }, [])

  if (loading) {
    return (
      <div className="panel-page">
        <header className="panel-page__header">
          <h1>{t('reports.title')}</h1>
          <p className="hint">{t('reports.subtitle')}</p>
        </header>
        <p className="hint">…</p>
      </div>
    )
  }

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('reports.title')}</h1>
        <p className="hint">{t('reports.subtitle')}</p>
      </header>

      <section className="panel-card">
        <h2>{t('reports.duplicates.title')}</h2>
        <p className="hint">{t('reports.duplicates.hint')}</p>
        {duplicates.length === 0 ? (
          <p className="hint">{t('reports.none')}</p>
        ) : (
          duplicates.map((group) => (
            <div key={`${group.media_type}-${group.ean}`} className="report-duplicate-group">
              <h4>
                {group.ean} <span className="media-type-badge">{t(`libraries.mediaType.${group.media_type}`)}</span>
              </h4>
              <ItemsTable rows={group.items} extraHeader={t('mediaItem.fields.price')} renderExtra={formatPrice} />
            </div>
          ))
        )}
      </section>

      <section className="panel-card">
        <h2>{t('reports.dataQuality.title')}</h2>
        <p className="hint">{t('reports.dataQuality.hint')}</p>
        {dataQuality.length === 0 ? (
          <p className="hint">{t('reports.none')}</p>
        ) : (
          <ItemsTable
            rows={dataQuality}
            extraHeader={t('reports.dataQuality.missingFields')}
            renderExtra={(row) => row.missing_fields.map((field) => t(`mediaItem.fields.${field}`)).join(', ')}
          />
        )}
      </section>

      <section className="panel-card">
        <h2>{t('reports.recentAdditions.title')}</h2>
        <p className="hint">{t('reports.recentAdditions.hint')}</p>
        {recentAdditions.length === 0 ? (
          <p className="hint">{t('reports.none')}</p>
        ) : (
          <ItemsTable
            rows={recentAdditions}
            extraHeader={t('reports.recentAdditions.addedAt')}
            renderExtra={(row) => (row.created_at ? new Date(row.created_at).toLocaleDateString() : '—')}
          />
        )}
      </section>

      {topLists && (
        <section className="panel-card">
          <h2>{t('reports.topLists.title')}</h2>
          <p className="hint">{t('reports.topLists.hint')}</p>
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
        </section>
      )}

      {captureSource && (
        <section className="panel-card">
          <h2>{t('reports.captureSource.title')}</h2>
          <p className="hint">{t('reports.captureSource.hint')}</p>
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
        </section>
      )}
    </div>
  )
}
