import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { apiClient } from '../../api/client'

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
 */
export function DashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [libraries, setLibraries] = useState<Library[] | null>(null)

  useEffect(() => {
    void (async () => {
      const { data } = await apiClient.get<Library[]>('/libraries')
      setLibraries(data)
    })()
  }, [])

  return (
    <div>
      <h1>{t('nav.home')}</h1>
      <p>
        {user?.name} ({user?.level})
      </p>

      <section>
        <h2>{t('libraries.title')}</h2>
        {libraries === null ? (
          <p>…</p>
        ) : libraries.length === 0 ? (
          <p>{t('dashboard.noLibraries')}</p>
        ) : (
          <>
            <ul className="library-list">
              {libraries.slice(0, PREVIEW_COUNT).map((lib) => (
                <li key={lib.id}>
                  <Link to={`/libraries/${lib.id}`}>{lib.name}</Link> — {t(`libraries.mediaType.${lib.media_type}`)} (
                  {lib.owner.name})
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
