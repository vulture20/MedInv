import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../api/client'

const SOURCE_URL = 'https://github.com/vulture20/MedInv'

/**
 * Displays the running app version (briefing: "Version definieren und in
 * Oberfläche einbauen"). Sourced from GET /api/version, which is public
 * (no auth) so it also renders on the login screen — single source of
 * truth is backend/config/medinv.php, not duplicated in frontend code.
 *
 * Also carries the "Quellcode"/source-code link required by AGPLv3 §13
 * (GitHub issue #207: MedInv relicensed from MIT to AGPL-3.0-or-later) —
 * this is the one component confirmed to render everywhere in the app,
 * including the anonymous login screen (LoginPage.tsx) and the footer of
 * every authenticated page (AppLayout.tsx), which is exactly the
 * visibility §13 requires for users interacting with the software over a
 * network. Points at the project's main repository rather than a
 * version-pinned tag, matching how most actively-maintained AGPL projects
 * with public git history satisfy this clause.
 */
export function VersionBadge() {
  const { t } = useTranslation()
  const [version, setVersion] = useState<string | null>(null)

  useEffect(() => {
    void apiClient
      .get<{ name: string; version: string }>('/version')
      .then(({ data }) => setVersion(data.version))
      .catch(() => setVersion(null))
  }, [])

  if (!version) return null

  return (
    <span className="version-badge">
      {version} ·{' '}
      <a href={SOURCE_URL} target="_blank" rel="noopener noreferrer">
        {t('common.sourceCode')}
      </a>
    </span>
  )
}
