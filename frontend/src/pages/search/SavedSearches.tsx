import { useState } from 'react'
import { useTranslation } from 'react-i18next'

/** GET/POST /saved-searches's response shape (SavedSearchController) — `filters` is whatever flat param object SearchFilters.tsx's filtersToRequestParams() produced at save time, round-tripped as-is. */
export interface SavedSearch {
  id: number
  name: string
  filters: Record<string, string | string[]>
}

/**
 * GitHub issue #73's "nice to have": named, reusable filter combinations.
 * A saved search's `filters` is applied by handing it straight back to
 * SearchPage.tsx's own `applyParams()` — the exact same shape a bookmarked
 * search URL already round-trips through, so this doesn't need its own,
 * separate "load a saved search" code path.
 */
export function SavedSearches({
  savedSearches,
  error,
  onApply,
  onSave,
  onDelete,
}: {
  savedSearches: SavedSearch[]
  error: string | null
  onApply: (filters: Record<string, string | string[]>) => void
  onSave: (name: string) => void
  onDelete: (id: number, name: string) => void
}) {
  const { t } = useTranslation()
  const [name, setName] = useState('')

  function submitSave(e: React.FormEvent) {
    e.preventDefault()
    if (!name.trim()) return
    onSave(name.trim())
    setName('')
  }

  return (
    <section className="panel-card search-saved">
      <h2>{t('search.savedSearches.title')}</h2>
      {error && <p role="alert">{error}</p>}

      <form onSubmit={submitSave} className="search-saved__save-form">
        <input
          type="text"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t('search.savedSearches.namePlaceholder')}
          aria-label={t('search.savedSearches.namePlaceholder')}
        />
        <button type="submit">{t('search.savedSearches.save')}</button>
      </form>

      {savedSearches.length === 0 ? (
        <p className="hint">{t('search.savedSearches.none')}</p>
      ) : (
        <ul className="search-saved__list">
          {savedSearches.map((saved) => (
            <li key={saved.id} className="search-saved__row">
              <button type="button" className="search-saved__apply-button" onClick={() => onApply(saved.filters)}>
                {saved.name}
              </button>
              <button
                type="button"
                onClick={() => {
                  if (window.confirm(t('search.savedSearches.confirmDelete', { name: saved.name }))) onDelete(saved.id, saved.name)
                }}
              >
                {t('libraries.delete')}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
