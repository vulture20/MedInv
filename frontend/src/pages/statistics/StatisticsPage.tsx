import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'

interface LibraryStats {
  library_id: number
  library_name: string
  media_type: string
  item_count: number
  total_value: number
  /** Which keys are present depends on media_type — only book libraries have `genre`, for instance (briefing 6./14.). */
  distributions: Record<string, Record<string, number>>
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

/**
 * Statistics overview (briefing 14.): per-library item count/value plus the
 * genre/language/year/publisher-artist-director distributions
 * StatisticsService computes (GitHub issue #7). "Zeitlicher Zuwachs des
 * Bestands" (growth over time) is out of scope here — see the service's
 * docblock.
 */
export function StatisticsPage() {
  const { t } = useTranslation()
  const [stats, setStats] = useState<LibraryStats[]>([])

  useEffect(() => {
    void apiClient.get<LibraryStats[]>('/statistics').then(({ data }) => setStats(data))
  }, [])

  return (
    <div>
      <h1>{t('statistics.title')}</h1>
      {stats.map((s) => {
        const dimensions = Object.entries(s.distributions).filter(([, data]) => Object.keys(data).length > 0)

        return (
          <section key={s.library_id} className="statistics-library">
            <h2>{s.library_name}</h2>
            <p>
              {t('statistics.itemCount')}: {s.item_count} — {t('statistics.totalValue')}: {s.total_value}
            </p>
            {dimensions.length === 0 ? (
              <p>{t('statistics.noDistributions')}</p>
            ) : (
              dimensions.map(([dimension, data]) => (
                <DistributionList key={dimension} title={t(`statistics.dimension.${dimension}`)} data={data} />
              ))
            )}
          </section>
        )
      })}
    </div>
  )
}
