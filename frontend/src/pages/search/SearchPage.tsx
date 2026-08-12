import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'

interface SearchHit {
  id: number
  title: string
  ean: string
  library: { id: number; name: string; media_type: string }
}

/**
 * Search results (briefing 13.). Reachable either via the header search box
 * (Header.tsx, which navigates here with `?query=`) or by searching again
 * directly on this page — the input+button here update the same `query` URL
 * param via setSearchParams rather than keeping separate local state, so
 * both entry points stay in sync and results stay bookmarkable/shareable.
 */
export function SearchPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const query = params.get('query') ?? ''
  const [queryInput, setQueryInput] = useState(query)
  const [fuzzy, setFuzzy] = useState(false)
  const [results, setResults] = useState<SearchHit[]>([])
  const [error, setError] = useState<string | null>(null)

  // Keeps the input field in sync when `query` changes from elsewhere (e.g. a
  // new search from the header box while already on this page).
  useEffect(() => {
    setQueryInput(query)
  }, [query])

  useEffect(() => {
    if (!query) {
      setResults([])
      return
    }
    setError(null)
    apiClient
      .get<SearchHit[]>('/search', { params: { query, fuzzy } })
      .then(({ data }) => setResults(data))
      .catch((err) => {
        // Previously missing entirely — any failed request (a validation
        // error, a session hiccup, ...) left `results` at its initial `[]`
        // with no indication anything went wrong, indistinguishable from a
        // real "no matches" result.
        console.error('Search failed:', err)
        setResults([])
        setError(t('search.error'))
      })
  }, [query, fuzzy, t])

  function submitSearch(e: React.FormEvent) {
    e.preventDefault()
    if (queryInput.trim()) setParams({ query: queryInput.trim() })
  }

  return (
    <div>
      <h1>{query ? t('search.resultsFor', { query }) : t('search.placeholder')}</h1>

      <form onSubmit={submitSearch} role="search">
        <input
          type="search"
          value={queryInput}
          onChange={(e) => setQueryInput(e.target.value)}
          placeholder={t('search.placeholder')}
          aria-label={t('search.placeholder')}
          autoFocus
        />
        <button type="submit">{t('search.submit')}</button>
      </form>

      <label>
        <input type="checkbox" checked={fuzzy} onChange={(e) => setFuzzy(e.target.checked)} />
        {t('search.fuzzy')}
      </label>

      {error && <p role="alert">{error}</p>}

      <ul>
        {results.map((hit) => (
          <li key={`${hit.library.id}-${hit.id}`}>
            {hit.title} — {hit.ean} — <em>{hit.library.name}</em>
          </li>
        ))}
      </ul>
    </div>
  )
}
