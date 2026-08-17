import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'

/** A Library, mirroring backend/app/Models/Library.php. */
interface Library {
  id: number
  name: string
  description: string | null
  media_type: 'book' | 'cd' | 'dvd_bluray'
  owner: { id: number; name: string }
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

  async function load() {
    const { data } = await apiClient.get<Library[]>('/libraries')
    setLibraries(data)
    setLoading(false)
  }

  useEffect(() => {
    void load()
  }, [])

  async function createLibrary(e: React.FormEvent) {
    e.preventDefault()
    await apiClient.post('/libraries', { name, description: description === '' ? null : description, media_type: mediaType })
    setName('')
    setDescription('')
    setCreating(false)
    await load()
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
    await apiClient.delete(`/libraries/${lib.id}`)
    await load()
  }

  function startEdit(lib: Library) {
    setEditingId(lib.id)
    setEditName(lib.name)
    setEditDescription(lib.description ?? '')
  }

  async function saveEdit(e: React.FormEvent) {
    e.preventDefault()
    if (editingId === null) return
    await apiClient.put(`/libraries/${editingId}`, { name: editName, description: editDescription === '' ? null : editDescription })
    setEditingId(null)
    await load()
  }

  return (
    <div className="panel-page">
      <header className="panel-page__header">
        <h1>{t('libraries.title')}</h1>
        <p className="hint">{t('libraries.subtitle')}</p>
      </header>

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
                <p className="hint">{lib.owner.name}</p>
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
