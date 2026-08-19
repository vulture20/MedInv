import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { formatPrice } from '../libraries/mediaItemFields'

interface LibraryStats {
  library_id: number
  library_name: string
  media_type: string
  item_count: number
  total_value: number
  /** GitHub issue #62: true when this library has an item whose `currency` (#58) disagrees with the admin-configured default (SystemSettingsPage.tsx) — total_value itself is still a plain currency-less sum either way, see StatisticsService::overviewFor()'s docblock. */
  currency_mismatch: boolean
  /** The admin-configured default currency (SystemSettingsPage.tsx), or null if unset — GitHub issue #105. Only meaningful to display alongside `total_value` when `currency_mismatch` is false; see formatPrice()'s (mediaItemFields.ts, GitHub issue #107) docblock. */
  default_currency: string | null
  /** Which keys are present depends on media_type — only book libraries have `genre`, for instance (briefing 6./14.). */
  distributions: Record<string, Record<string, number>>
}

/** One point of GET /statistics/value-history's per-library or accumulated series (StatisticsService::valueHistoryFor()). */
interface ValueHistoryPoint {
  date: string
  item_count: number
  total_value: number
}

interface LibraryValueHistory {
  library_id: number
  library_name: string
  /** 'estimated' points are derived from item created_at (no real snapshot existed yet); 'snapshot' points are the daily job's real numbers. */
  series: (ValueHistoryPoint & { source: 'estimated' | 'snapshot' })[]
}

interface ValueHistoryResponse {
  libraries: LibraryValueHistory[]
  accumulated: { series: ValueHistoryPoint[] }
  /** The earliest real snapshot date anywhere in the system, or null if the daily job hasn't run yet at all. */
  cutover_date: string | null
}

/** Bars beyond this many are folded into a "+N more" note rather than rendered (briefing 14., ">7 classes" per dataviz guidance). */
const MAX_BARS = 8

/**
 * One dimension's breakdown (e.g. genre, language, year) as a single-hue
 * horizontal bar list — a sequential "compare magnitude" chart, per the
 * dataviz skill's form guidance for this job. Every value is also given as
 * a direct text label, so nothing is bar-length-only.
 */
function DistributionList({ title, data }: { title: string; data: Record<string, number> }) {
  const { t } = useTranslation()
  const entries = Object.entries(data)

  if (entries.length === 0) return null

  const shown = entries.slice(0, MAX_BARS)
  const hiddenCount = entries.length - shown.length
  const max = Math.max(...entries.map(([, count]) => count))

  return (
    <div className="distribution">
      <h4>{title}</h4>
      <ul className="distribution__bars">
        {shown.map(([label, count]) => (
          <li key={label} className="distribution__row">
            <span className="distribution__label" title={label}>
              {label}
            </span>
            <span className="distribution__track">
              <span className="distribution__fill" style={{ width: `${(count / max) * 100}%` }} />
            </span>
            <span className="distribution__value">{count}</span>
          </li>
        ))}
      </ul>
      {hiddenCount > 0 && <p className="distribution__more">{t('statistics.moreCount', { count: hiddenCount })}</p>}
    </div>
  )
}

const CHART_WIDTH = 480
const CHART_HEIGHT = 110
const CHART_PAD = 8

/** date -> x/y position within the chart's viewBox, given the series' own date/value range. */
function makeProjection(points: ValueHistoryPoint[]) {
  const dateMs = (d: string) => new Date(d).getTime()
  const minDate = dateMs(points[0].date)
  const maxDate = dateMs(points[points.length - 1].date)
  const dateSpan = maxDate - minDate || 1
  const maxValue = Math.max(...points.map((p) => p.total_value), 0)

  return (p: ValueHistoryPoint): [number, number] => {
    const x = CHART_PAD + ((dateMs(p.date) - minDate) / dateSpan) * (CHART_WIDTH - 2 * CHART_PAD)
    const y = maxValue === 0 ? CHART_HEIGHT - CHART_PAD : CHART_HEIGHT - CHART_PAD - (p.total_value / maxValue) * (CHART_HEIGHT - 2 * CHART_PAD)
    return [x, y]
  }
}

function pathFor(points: ValueHistoryPoint[], project: ReturnType<typeof makeProjection>): string {
  return points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${project(p)[0].toFixed(1)} ${project(p)[1].toFixed(1)}`).join(' ')
}

/**
 * "Zeitlicher Zuwachs des Bestands" (briefing 14., GitHub issue #30) as a
 * line/area chart. Unlike the distributions above — a "compare magnitude"
 * job, suited to a bar list — value-over-time is fundamentally a trend,
 * which calls for a line chart instead. Split into two visually distinct
 * segments at cutoverDate: a dashed, muted line for the created_at-derived
 * estimate (no real daily snapshot existed yet for that period), solid for
 * the real snapshot data from cutoverDate onward — see
 * StatisticsService::valueHistoryFor()'s docblock for the full rationale.
 * Every plotted point also carries an exact-value tooltip, so nothing here
 * is line-position-only.
 */
function ValueHistoryChart({ title, points, cutoverDate }: { title: string; points: ValueHistoryPoint[]; cutoverDate: string | null }) {
  const { t } = useTranslation()

  if (points.length === 0) {
    return (
      <div className="value-history">
        <h4>{title}</h4>
        <p>{t('statistics.valueHistory.noData')}</p>
      </div>
    )
  }

  let estimatedPoints: ValueHistoryPoint[] = []
  let realPoints: ValueHistoryPoint[] = points

  if (cutoverDate !== null) {
    const splitIndex = points.findIndex((p) => p.date >= cutoverDate)
    if (splitIndex === -1) {
      estimatedPoints = points
      realPoints = []
    } else if (splitIndex > 0) {
      // The boundary point is included in both halves so the dashed and
      // solid segments visually connect without a gap.
      estimatedPoints = points.slice(0, splitIndex + 1)
      realPoints = points.slice(splitIndex)
    }
  } else {
    estimatedPoints = points
    realPoints = []
  }

  const strictlyEstimatedCount = estimatedPoints.length - (realPoints.length > 0 ? 1 : 0)
  const hasEstimated = strictlyEstimatedCount > 0
  const project = makeProjection(points)
  const last = points[points.length - 1]

  return (
    <div className="value-history">
      <h4>{title}</h4>
      <svg viewBox={`0 0 ${CHART_WIDTH} ${CHART_HEIGHT}`} className="value-history__chart" role="img" aria-label={title}>
        {estimatedPoints.length > 1 && (
          <path d={pathFor(estimatedPoints, project)} className="value-history__line value-history__line--estimated" fill="none" />
        )}
        {realPoints.length > 1 && <path d={pathFor(realPoints, project)} className="value-history__line value-history__line--real" fill="none" />}
        {points.map((p) => {
          const [x, y] = project(p)
          const isEstimated = cutoverDate !== null ? p.date < cutoverDate : true

          return (
            <circle key={p.date} cx={x} cy={y} r={2.5} className={isEstimated ? 'value-history__dot--estimated' : 'value-history__dot--real'}>
              <title>
                {p.date}: {p.total_value}
              </title>
            </circle>
          )
        })}
      </svg>
      <p className="value-history__summary">{t('statistics.valueHistory.current', { value: last.total_value, date: last.date })}</p>
      {hasEstimated &&
        (cutoverDate === null ? (
          <p className="value-history__note">{t('statistics.valueHistory.estimatedNoteAll')}</p>
        ) : (
          <p className="value-history__note">{t('statistics.valueHistory.estimatedNoteUntil', { date: cutoverDate })}</p>
        ))}
    </div>
  )
}

/**
 * Statistics overview (briefing 14.): per-library item count/value, the
 * genre/language/year/publisher-artist-director distributions
 * (GitHub issue #7), and the value-over-time chart (GitHub issue #30) — one
 * line per library plus an accumulated curve — all scoped through
 * LibraryAccessService. Every one of these is a chart/aggregated sum, which
 * is what actually distinguishes a Statistik from an Auswertung — every
 * browsable table (duplicates, data quality, top lists, recent additions,
 * capture method/metadata source, and — since GitHub issue #103 — the
 * sharing overview and per-user activity GitHub issue #74 originally put
 * here) lives on the "Auswertungen" page instead, ReportsPage.tsx/
 * ReportDetailPage.tsx.
 *
 * Card layout matches LibrariesPage.tsx's (.panel-page/.panel-card, see
 * index.css's shared docblock) — the accumulated chart and each library get
 * their own card, the same "each distinct thing gets its own card"
 * treatment LibrariesPage.tsx's per-library cards use, reusing its
 * .library-card__header (name + a media-type badge here instead of
 * LibrariesPage's) and .library-detail__meta (item count/total value here
 * instead of LibraryDetailPage's media-type/owner line) rather than
 * inventing parallel classes for what's structurally the same header shape.
 */
export function StatisticsPage() {
  const { t, i18n } = useTranslation()
  const [stats, setStats] = useState<LibraryStats[]>([])
  const [history, setHistory] = useState<ValueHistoryResponse | null>(null)
  const [loading, setLoading] = useState(true)
  // GitHub issue #105 — previously missing entirely — a failed request (a
  // session hiccup, a 500, ...) left `stats`/`history` at their initial
  // empty values with no indication anything went wrong, indistinguishable
  // from a genuine "no libraries visible yet" result. Same fix SearchPage.tsx
  // already got for the identical gap.
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setError(null)
    void Promise.all([
      apiClient.get<LibraryStats[]>('/statistics').then(({ data }) => setStats(data)),
      apiClient.get<ValueHistoryResponse>('/statistics/value-history').then(({ data }) => setHistory(data)),
    ])
      .catch((err) => {
        console.error('Failed to load statistics:', err)
        setError(t('statistics.error'))
      })
      .finally(() => setLoading(false))
  }, [t])

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('statistics.title')}</h1>
        <p className="hint">{t('statistics.subtitle')}</p>
      </header>

      {error && <p role="alert">{error}</p>}

      {loading ? (
        <p className="hint">…</p>
      ) : (
        <>
          {history && history.accumulated.series.length > 0 && (
            <section className="panel-card">
              <h2>{t('statistics.valueHistory.accumulatedTitle')}</h2>
              <ValueHistoryChart
                title={t('statistics.valueHistory.title')}
                points={history.accumulated.series}
                cutoverDate={history.cutover_date}
              />
            </section>
          )}

          {stats.length === 0 ? (
            <p className="hint">{t('statistics.none')}</p>
          ) : (
            stats.map((s) => {
              const dimensions = Object.entries(s.distributions).filter(([, data]) => Object.keys(data).length > 0)
              const libraryHistory = history?.libraries.find((h) => h.library_id === s.library_id)

              return (
                <section key={s.library_id} className="panel-card">
                  <div className="library-card__header">
                    <h2>{s.library_name}</h2>
                    <span className="media-type-badge">{t(`libraries.mediaType.${s.media_type}`)}</span>
                  </div>
                  <p className="library-detail__meta hint">
                    <span>
                      {t('statistics.itemCount')}: {s.item_count}
                    </span>
                    <span>
                      {t('statistics.totalValue')}: {formatPrice(s.total_value, s.currency_mismatch ? null : s.default_currency, i18n.language)}
                    </span>
                  </p>
                  {s.currency_mismatch && <p className="warning warning--danger">{t('statistics.currencyMismatchWarning')}</p>}
                  {libraryHistory && (
                    <ValueHistoryChart title={t('statistics.valueHistory.title')} points={libraryHistory.series} cutoverDate={history?.cutover_date ?? null} />
                  )}
                  {dimensions.length === 0 ? (
                    <p className="hint">{t('statistics.noDistributions')}</p>
                  ) : (
                    dimensions.map(([dimension, data]) => (
                      <DistributionList key={dimension} title={t(`statistics.dimension.${dimension}`)} data={data} />
                    ))
                  )}
                </section>
              )
            })
          )}
        </>
      )}
    </div>
  )
}
