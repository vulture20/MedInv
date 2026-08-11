import { useEffect, useState } from 'react'
import { apiClient } from '../api/client'

/**
 * Displays the running app version (briefing: "Version definieren und in
 * Oberfläche einbauen"). Sourced from GET /api/version, which is public
 * (no auth) so it also renders on the login screen — single source of
 * truth is backend/config/medinv.php, not duplicated in frontend code.
 */
export function VersionBadge() {
  const [version, setVersion] = useState<string | null>(null)

  useEffect(() => {
    void apiClient
      .get<{ name: string; version: string }>('/version')
      .then(({ data }) => setVersion(data.version))
      .catch(() => setVersion(null))
  }, [])

  if (!version) return null

  return <span className="version-badge">{version}</span>
}
