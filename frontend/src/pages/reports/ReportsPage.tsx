import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { REPORTS } from './reportTypes'

/**
 * "Auswertungen" overview (GitHub issue #74, list/detail split GitHub issue
 * #101) — just a title + short description per report, same "view" button
 * pattern LibrariesPage.tsx's cards use to navigate to LibraryDetailPage.
 * Nothing is fetched here: every report used to load eagerly (five parallel
 * requests) just to render five sections on one long page, only one of
 * which a user usually cares about at a time — the actual table now lives
 * on its own page (ReportDetailPage.tsx, `/reports/:key`) and is fetched
 * only once that report is actually opened.
 */
export function ReportsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('reports.title')}</h1>
        <p className="hint">{t('reports.subtitle')}</p>
      </header>

      {REPORTS.map((report) => (
        <section key={report.key} className="panel-card">
          <h2>{t(report.titleKey)}</h2>
          <p className="hint">{t(report.hintKey)}</p>
          <button type="button" onClick={() => navigate(`/reports/${report.key}`)}>
            {t('reports.view')}
          </button>
        </section>
      ))}
    </div>
  )
}
