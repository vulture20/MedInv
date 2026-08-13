import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
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
 */
export function LibrariesPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [libraries, setLibraries] = useState<Library[]>([])
  const [name, setName] = useState('')
  const [mediaType, setMediaType] = useState<'book' | 'cd' | 'dvd_bluray'>('book')
  const [loading, setLoading] = useState(true)

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
    await apiClient.post('/libraries', { name, media_type: mediaType })
    setName('')
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
    <div>
      <h1>{t('libraries.title')}</h1>

      {loading ? (
        <p>…</p>
      ) : (
        <ul className="library-list">
          {libraries.map((lib) =>
            editingId === lib.id ? (
              <li key={lib.id}>
                <form onSubmit={saveEdit}>
                  <label>
                    {t('common.name')}
                    <input value={editName} onChange={(e) => setEditName(e.target.value)} required />
                  </label>
                  <label>
                    {t('libraries.descriptionLabel')}
                    <textarea value={editDescription} onChange={(e) => setEditDescription(e.target.value)} />
                  </label>
                  <button type="submit">{t('admin.actions.save')}</button>{' '}
                  <button type="button" onClick={() => setEditingId(null)}>
                    {t('admin.actions.cancel')}
                  </button>
                </form>
              </li>
            ) : (
              <li key={lib.id}>
                <strong>{lib.name}</strong> — {t(`libraries.mediaType.${lib.media_type}`)} ({lib.owner.name})
                {lib.description && <p>{lib.description}</p>}
                <Link to={`/libraries/${lib.id}`}>{t('libraries.view')}</Link>
                {canDelete(lib) && (
                  <>
                    {' '}
                    <button type="button" onClick={() => startEdit(lib)}>
                      {t('admin.actions.edit')}
                    </button>{' '}
                    <button type="button" onClick={() => void deleteLibrary(lib)}>
                      {t('libraries.delete')}
                    </button>
                  </>
                )}
              </li>
            ),
          )}
        </ul>
      )}

      {user?.level !== 'guest' && (
        <form onSubmit={createLibrary}>
          <h2>{t('libraries.create')}</h2>
          <label>
            {t('common.name')}
            <input value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
          <label>
            {/* media_type is fixed once created (briefing 5.) — no edit UI for it exists anywhere. */}
            {t('libraries.mediaTypeLabel')}
            <select value={mediaType} onChange={(e) => setMediaType(e.target.value as typeof mediaType)}>
              <option value="book">{t('libraries.mediaType.book')}</option>
              <option value="cd">{t('libraries.mediaType.cd')}</option>
              <option value="dvd_bluray">{t('libraries.mediaType.dvd_bluray')}</option>
            </select>
          </label>
          <button type="submit">{t('libraries.create')}</button>
        </form>
      )}
    </div>
  )
}
