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

/** Search results (briefing 13.) — each hit shows its source library. */
export function SearchPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const query = params.get('query') ?? ''
  const [fuzzy, setFuzzy] = useState(false)
  const [results, setResults] = useState<SearchHit[]>([])

  useEffect(() => {
    if (!query) return
    void apiClient.get<SearchHit[]>('/search', { params: { query, fuzzy } }).then(({ data }) => setResults(data))
  }, [query, fuzzy])

  return (
    <div>
      <h1>{t('search.resultsFor', { query })}</h1>
      <label>
        <input type="checkbox" checked={fuzzy} onChange={(e) => setFuzzy(e.target.checked)} />
        {t('search.fuzzy')}
      </label>
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
