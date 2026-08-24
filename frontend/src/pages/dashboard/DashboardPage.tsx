import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { apiClient } from '../../api/client'
import { describeError } from '../admin/adminErrors'
import { MediaItemDetailDialog } from '../libraries/MediaItemDetailDialog'
import { coverSrc, type LibraryRef, type MediaItem, type MediaType } from '../libraries/mediaItemFields'

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

/** GET /dashboard/random-items's response shape: a full media item (SearchService::randomItemsFor() returns the whole Eloquent model, no field selection, same as GET /search) plus its owning library — a carousel spans every visible library at once, so each item carries its own `library` rather than the page having one fixed library for all of them (same reasoning SearchPage.tsx's own SearchHit already documents). */
interface RandomMediaItem extends MediaItem {
  library: LibraryRef
}

type RandomMedia = Record<MediaType, RandomMediaItem[]>

/** The order the three carousels render in — CD, Buch, DVD/Blu-ray, per GitHub issue #116. */
const CAROUSEL_ORDER: MediaType[] = ['cd', 'book', 'dvd_bluray']

/**
 * One media-type's cover carousel (GitHub issue #116) — up to 25 randomly
 * selected items (SearchService::randomItemsFor(), re-rolled on every
 * DashboardPage mount) across every library visible to the user, each
 * showing its generated thumbnail (the same `.../cover/thumbnail` endpoint
 * SearchPage.tsx/LibraryDetailPage.tsx already use) with its title below.
 *
 * The tile list is rendered twice back to back, and CSS slides the whole
 * track left by exactly half its width in a loop (`dashboard-carousel-
 * scroll` in index.css) — a plain, classic marquee technique: once the
 * first copy has scrolled fully out of view, the second copy is sitting
 * exactly where the first one started, so the loop point is invisible. The
 * animation's duration scales with the item count (`--dashboard-carousel-
 * duration`) so a shorter list doesn't end up scrolling noticeably faster
 * than a fuller one for the same "slow" impression. Paused on hover/focus
 * so a tile can actually be read/clicked, and skipped entirely under
 * `prefers-reduced-motion` (index.css).
 *
 * An item without its own cover gets a plain placeholder tile instead of
 * being left out of the random draw — dropping cover-less items would bias
 * the "random" selection towards whichever items happen to have artwork.
 *
 * `paused` (GitHub issue #119) additionally stops the animation outright
 * while this specific carousel's own MediaItemDetailDialog is open. A
 * native <dialog> moves keyboard focus into itself when it opens, i.e. out
 * of this carousel's DOM subtree — so the existing :focus-within pause
 * (index.css, GitHub issue #118) stops applying for exactly as long as the
 * dialog is shown, and the row kept scrolling behind it. DashboardPage
 * only ever passes `true` for the one carousel whose media type matches
 * the currently open item, never the other two.
 */
function MediaCarousel({
  mediaType,
  items,
  paused,
  onSelect,
}: {
  mediaType: MediaType
  items: RandomMediaItem[]
  paused: boolean
  onSelect: (item: RandomMediaItem) => void
}) {
  const { t } = useTranslation()

  return (
    <section className="panel-card dashboard-carousel">
      <h2>{t(`libraries.mediaType.${mediaType}`)}</h2>
      <p className="hint">{t('dashboard.randomMedia.subtitle')}</p>
      {items.length === 0 ? (
        <p className="hint">{t('dashboard.randomMedia.none')}</p>
      ) : (
        <div className={`dashboard-carousel__viewport${paused ? ' dashboard-carousel__viewport--paused' : ''}`}>
          <div className="dashboard-carousel__track" style={{ '--dashboard-carousel-duration': `${Math.max(items.length * 4, 20)}s` } as React.CSSProperties}>
            {[...items, ...items].map((item, i) => (
              <button key={`${item.id}-${i}`} type="button" className="dashboard-carousel__tile" onClick={() => onSelect(item)}>
                {item.cover_path ? (
                  <img
                    className="dashboard-carousel__cover"
                    src={coverSrc(item.library.id, item.id, item.cover_path, '/thumbnail')}
                    crossOrigin="use-credentials"
                    alt=""
                  />
                ) : (
                  <span className="dashboard-carousel__cover dashboard-carousel__cover--placeholder" aria-hidden="true" />
                )}
                <span className="dashboard-carousel__title">{item.title}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </section>
  )
}

/**
 * Startseite (briefing 11.2). Shows a short excerpt of every library
 * currently visible to this user — GET /libraries already applies
 * LibraryAccessService::visibleLibrariesQuery() server-side (guest/user/
 * admin levels × per-library shares, 4.2–4.3), so this just renders
 * whatever comes back, same as LibrariesPage.tsx.
 *
 * GitHub issue #116: three cover carousels (CD/Buch/DVD-Blu-ray, GET
 * /dashboard/random-items) sit above the library excerpt — a visual,
 * browsable glimpse of the actual collection rather than only a list of
 * library names. The library excerpt below is kept as-is; it serves a
 * different purpose (quick access to the libraries themselves, owner
 * included) that the carousels don't replace.
 *
 * Card layout matches LibrariesPage.tsx's/StatisticsPage.tsx's (.panel-page/
 * .panel-card, see index.css's shared docblock). Unlike LibrariesPage.tsx's
 * one-card-per-library treatment, the library excerpt stays a single card
 * with a compact row per library (reusing .media-type-badge) instead of the
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

  const [randomMedia, setRandomMedia] = useState<RandomMedia | null>(null)
  const [randomMediaError, setRandomMediaError] = useState<string | null>(null)
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [selectedLibrary, setSelectedLibrary] = useState<LibraryRef | null>(null)

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

  useEffect(() => {
    apiClient
      .get<RandomMedia>('/dashboard/random-items')
      .then(({ data }) => setRandomMedia(data))
      .catch((err) => {
        console.error('Failed to load random media:', err)
        setRandomMediaError(describeError(err, t))
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function openItem(item: RandomMediaItem) {
    setSelectedItem(item)
    setSelectedLibrary(item.library)
  }

  /**
   * GitHub issue #118 — closing a native <dialog> (Esc, backdrop click, its
   * own close button) restores focus to whichever element opened it, here
   * the clicked carousel tile's <button>. `.dashboard-carousel__track`'s
   * auto-scroll animation is deliberately paused while focus sits inside
   * its carousel (index.css's `:focus-within` rule, so a keyboard user
   * tabbed onto a tile isn't fought by it sliding out from under them) —
   * without this blur, that same rule kept the row paused indefinitely
   * after closing the dialog, since the now-closed dialog's own returned
   * focus never moves again on its own; a click/tap or keyboard focus into
   * the carousel to open the dialog in the first place both leave the
   * tile focused this same way, matching what the user reported (mouse
   * click and touch alike). Scoped to `.dashboard-carousel` specifically
   * rather than blurring on every close — MediaItemDetailDialog's other
   * callers (SearchPage.tsx, LibraryDetailPage.tsx) rely on the standard
   * browser behavior of returning focus to their own triggering row/button
   * for keyboard users to continue from, which this must not disturb.
   */
  function closeDialog() {
    if (document.activeElement instanceof HTMLElement && document.activeElement.closest('.dashboard-carousel')) {
      document.activeElement.blur()
    }
    setSelectedItem(null)
    setSelectedLibrary(null)
  }

  /** Patches the item in place within its carousel, mirroring SearchPage.tsx's identically-purposed onUpdated handler. */
  function handleUpdated(updated: MediaItem) {
    if (selectedLibrary) {
      setRandomMedia(
        (prev) =>
          prev && {
            ...prev,
            [selectedLibrary.media_type]: prev[selectedLibrary.media_type].map((item) => (item.id === updated.id ? { ...item, ...updated } : item)),
          }
      )
    }
    setSelectedItem(updated)
  }

  /** The item no longer belongs where this carousel found it (deleted, or moved to a different library) — drop it rather than re-rolling the whole selection, same reasoning SearchPage.tsx's removeSelectedFromResults() documents. */
  function removeSelectedFromCarousel() {
    if (selectedLibrary && selectedItem) {
      setRandomMedia(
        (prev) =>
          prev && {
            ...prev,
            [selectedLibrary.media_type]: prev[selectedLibrary.media_type].filter((item) => item.id !== selectedItem.id),
          }
      )
    }
    closeDialog()
  }

  return (
    <div className="panel-page panel-page--wide">
      <header className="panel-page__header">
        <h1>{t('nav.home')}</h1>
        <p className="hint">
          {user?.name} ({user ? t(`admin.level.${user.level}`) : ''})
        </p>
      </header>

      {randomMediaError && <p role="alert">{randomMediaError}</p>}
      {randomMedia &&
        CAROUSEL_ORDER.map((mediaType) => (
          <MediaCarousel
            key={mediaType}
            mediaType={mediaType}
            items={randomMedia[mediaType]}
            paused={selectedItem !== null && selectedLibrary?.media_type === mediaType}
            onSelect={openItem}
          />
        ))}

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

      <MediaItemDetailDialog
        library={selectedLibrary ?? { id: 0, name: '', media_type: 'book', owner: { id: 0, name: '' } }}
        item={selectedItem}
        libraries={libraries ?? []}
        onClose={closeDialog}
        onUpdated={handleUpdated}
        onDeleted={removeSelectedFromCarousel}
        onMoved={removeSelectedFromCarousel}
      />
    </div>
  )
}
