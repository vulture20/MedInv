import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'

interface LibraryStats {
  library_id: number
  library_name: string
  media_type: string
  item_count: number
  total_value: number
}

/**
 * Statistics overview (briefing 14.). Currently shows the per-library
 * counts/value StatisticsService provides today; the distribution charts
 * (genre/language/year/etc.) are a TODO on the backend side (see
 * backend/app/Domain/Statistics/StatisticsService.php).
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
      <table>
        <thead>
          <tr>
            <th>{t('libraries.title')}</th>
            <th>{t('statistics.itemCount')}</th>
            <th>{t('statistics.totalValue')}</th>
          </tr>
        </thead>
        <tbody>
          {stats.map((s) => (
            <tr key={s.library_id}>
              <td>{s.library_name}</td>
              <td>{s.item_count}</td>
              <td>{s.total_value}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
