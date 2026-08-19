import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { describeError } from '../admin/adminErrors'

/** A Library, mirroring backend/app/Models/Library.php. */
interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
  /** GitHub issue #95 — LibraryController::index() only, not part of the Library model itself. */
  item_count: number
}

/**
 * Library list + creation (briefing 5.). Visibility is entirely
 * backend-driven (GET /libraries already applies LibraryAccessService), so
 * this component doesn't need its own permission logic beyond hiding the
 * "create" form from guests (4.2).
 *
 * Card layout matches SettingsPage.tsx/CapturePage.tsx's (.panel-page/
 * .panel-card, see index.css's shared docblock) — one card per library,
 * same "each distinct thing gets its own card" treatment CapturePage.tsx's
 * result cards use, plus a reveal-on-demand create form (like Capture's
 * camera/text-file import) rather than an always-open form competing with
 * the list for attention on a page whose primary job is browsing, not
 * creating.
 */
export function LibrariesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [libraries, setLibraries] = useState<Library[]>([])
  const [name, setName] = useState('')
  // GitHub issue #88 — the backend already accepted `description` on create
  // (LibraryController::store()); only the create form itself never asked
  // for one, unlike the edit form below, which has had one all along.
  const [description, setDescription] = useState('')
  const [mediaType, setMediaType] = useState<'book' | 'cd' | 'dvd_bluray'>('book')
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)

  // Inline editing of name/description (briefing 5.), restricted to
  // owner/admin like deletion below — same PUT /libraries/{id} endpoint
  // LibraryDetailPage.tsx's edit form uses.
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editName, setEditName] = useState('')
  const [editDescription, setEditDescription] = useState('')

  // GitHub issue #108 — previously missing entirely on this page (unlike
  // SearchPage.tsx/StatisticsPage.tsx/ReportDetailPage.tsx, which already
  // got this fix): a failed load() left `loading` stuck at `true` forever
  // (setLoading(false) never ran), and a failed create/delete/edit just
  // silently did nothing with no feedback at all. One shared error state
  // for all four actions — simpler than per-action state given how few,
  // and how similar in shape, they are on this page.
  const [error, setError] = useState<string | null>(null)

  async function load() {
    setError(null)
    try {
      const { data } = await apiClient.get<Library[]>('/libraries')
      setLibraries(data)
    } catch (err) {
      setError(describeError(err, t))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function createLibrary(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await apiClient.post('/libraries', { name, description: description === '' ? null : description, media_type: mediaType })
      setName('')
      setDescription('')
      setCreating(false)
      await load()
    } catch (err) {
      setError(describeError(err, t))
    }
  }

  /**
   * Mirrors LibraryAccessService::canWrite() (admin or owner) — the API
   * already re-checks this server-side on DELETE, this only decides
   * whether to show the button at all.
   */
  function canDelete(lib: Library): boolean {
    return user?.level === 'admin' || lib.owner.id === user?.id
  }

  async function deleteLibrary(lib: Library) {
    if (!window.confirm(t('libraries.confirmDelete', { name: lib.name }))) return
    setError(null)
    try {
      await apiClient.delete(`/libraries/${lib.id}`)
      await load()
    } catch (err) {
      setError(describeError(err, t))
    }
  }

  function startEdit(lib: Library) {
    setEditingId(lib.id)
    setEditName(lib.name)
    setEditDescription(lib.description ?? '')
  }

  async function saveEdit(e: React.FormEvent) {
    e.preventDefault()
    if (editingId === null) return
    setError(null)
    try {
      await apiClient.put(`/libraries/${editingId}`, { name: editName, description: editDescription === '' ? null : editDescription })
      setEditingId(null)
      await load()
    } catch (err) {
      setError(describeError(err, t))
    }
  }

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('libraries.title')}</h1>
        <p className="hint">{t('libraries.subtitle')}</p>
      </header>

      {error && <p role="alert">{error}</p>}

      {user?.level !== 'guest' && (
        <>
          <button type="button" onClick={() => setCreating((prev) => !prev)}>
            {t(creating ? 'admin.actions.cancel' : 'libraries.create')}
          </button>

          {creating && (
            <section className="panel-card">
              <h2>{t('libraries.create')}</h2>
              <p className="hint">{t('libraries.createHint')}</p>
              <form onSubmit={(e) => void createLibrary(e)}>
                <label>
                  {t('common.name')}
                  <input className="panel-select" value={name} onChange={(e) => setName(e.target.value)} required />
                </label>
                <label>
                  {t('libraries.descriptionLabel')}
                  <textarea className="panel-select" value={description} onChange={(e) => setDescription(e.target.value)} />
                </label>
                <label>
                  {/* media_type is fixed once created (briefing 5.) — libraries.createHint above says so. */}
                  {t('libraries.mediaTypeLabel')}
                  <select className="panel-select" value={mediaType} onChange={(e) => setMediaType(e.target.value as typeof mediaType)}>
                    <option value="book">{t('libraries.mediaType.book')}</option>
                    <option value="cd">{t('libraries.mediaType.cd')}</option>
                    <option value="dvd_bluray">{t('libraries.mediaType.dvd_bluray')}</option>
                  </select>
                </label>
                <button type="submit">{t('libraries.create')}</button>
              </form>
            </section>
          )}
        </>
      )}

      {loading ? (
        <p className="hint">…</p>
      ) : libraries.length === 0 ? (
        <p className="hint">{t('libraries.none')}</p>
      ) : (
        libraries.map((lib) => (
          <section key={lib.id} className="panel-card library-card">
            {editingId === lib.id ? (
              <form onSubmit={(e) => void saveEdit(e)}>
                <label>
                  {t('common.name')}
                  <input className="panel-select" value={editName} onChange={(e) => setEditName(e.target.value)} required />
                </label>
                <label>
                  {t('libraries.descriptionLabel')}
                  <textarea className="panel-select" value={editDescription} onChange={(e) => setEditDescription(e.target.value)} />
                </label>
                <button type="submit">{t('admin.actions.save')}</button>{' '}
                <button type="button" onClick={() => setEditingId(null)}>
                  {t('admin.actions.cancel')}
                </button>
              </form>
            ) : (
              <>
                <div className="library-card__header">
                  <h2>{lib.name}</h2>
                  <span className="media-type-badge">{t(`libraries.mediaType.${lib.media_type}`)}</span>
                </div>
                <p className="hint">
                  {/* GitHub issue #95 — reuses the same libraries.itemsTitle key LibraryDetailPage.tsx's own item-list header already uses. */}
                  {lib.owner.name} · {t('libraries.itemsTitle', { count: lib.item_count })}
                </p>
                {lib.description && <p>{lib.description}</p>}
                <div className="library-card__actions">
                  <button type="button" onClick={() => navigate(`/libraries/${lib.id}`)}>
                    {t('libraries.view')}
                  </button>
                  {canDelete(lib) && (
                    <>
                      <button type="button" onClick={() => startEdit(lib)}>
                        {t('admin.actions.edit')}
                      </button>
                      <button type="button" onClick={() => void deleteLibrary(lib)}>
                        {t('libraries.delete')}
                      </button>
                    </>
                  )}
                </div>
              </>
            )}
          </section>
        ))
      )}
    </div>
  )
}
