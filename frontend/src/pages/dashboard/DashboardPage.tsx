import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { apiClient } from '../../api/client'
import { describeError } from '../admin/adminErrors'

/** A Library, mirroring backend/app/Models/Library.php (see LibrariesPage.tsx). */
interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
}

/** How many libraries to show in the excerpt before pointing to the full list. */
const PREVIEW_COUNT = 5

/**
 * Startseite (briefing 11.2). Shows a short excerpt of every library
 * currently visible to this user — GET /libraries already applies
 * LibraryAccessService::visibleLibrariesQuery() server-side (guest/user/
 * admin levels × per-library shares, 4.2–4.3), so this just renders
 * whatever comes back, same as LibrariesPage.tsx.
 *
 * Card layout matches LibrariesPage.tsx's/StatisticsPage.tsx's (.panel-page/
 * .panel-card, see index.css's shared docblock). Unlike LibrariesPage.tsx's
 * one-card-per-library treatment, this is a quick-glance excerpt rather
 * than the management view, so the preview stays a single card with a
 * compact row per library (reusing .media-type-badge) instead of the
 * heavier full card per entry.
 */
export function DashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [libraries, setLibraries] = useState<Library[] | null>(null)
  // GitHub issue #110 — previously missing entirely: a failed request left
  // `libraries` at its initial `null` forever, indistinguishable from
  // "still loading" (the `libraries === null` branch below shows "…" either
  // way) — the app's own home page could silently hang with no explanation.
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    void (async () => {
      try {
        const { data } = await apiClient.get<Library[]>('/libraries')
        setLibraries(data)
      } catch (err) {
        setError(describeError(err, t))
      }
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('nav.home')}</h1>
        <p className="hint">
          {user?.name} ({user?.level})
        </p>
      </header>

      <section className="panel-card">
        <h2>{t('libraries.title')}</h2>
        {error && <p role="alert">{error}</p>}
        {libraries === null ? (
          error ? null : <p className="hint">…</p>
        ) : libraries.length === 0 ? (
          <p className="hint">{t('dashboard.noLibraries')}</p>
        ) : (
          <>
            <ul className="library-list">
              {libraries.slice(0, PREVIEW_COUNT).map((lib) => (
                <li key={lib.id} className="library-list__row">
                  <Link to={`/libraries/${lib.id}`} className="library-list__link">
                    <span className="library-list__name">{lib.name}</span>
                    <span className="media-type-badge">{t(`libraries.mediaType.${lib.media_type}`)}</span>
                  </Link>
                  <span className="hint">{lib.owner.name}</span>
                </li>
              ))}
            </ul>
            {libraries.length > PREVIEW_COUNT && (
              <p>
                <Link to="/libraries">{t('dashboard.allLibraries', { count: libraries.length })}</Link>
              </p>
            )}
          </>
        )}
      </section>
    </div>
  )
}
