import { useTranslation } from 'react-i18next'

interface SpinnerProps {
  /** Center the spinner in the full viewport height, e.g. while gating the whole app (briefing 11.1). */
  fullPage?: boolean
}

/** A small CSS-animated loading indicator, used while an async gate (e.g. RequireAuth) is pending. */
export function Spinner({ fullPage = false }: SpinnerProps) {
  const { t } = useTranslation()

  const spinner = <span className="spinner" role="status" aria-label={t('common.loading')} />

  if (!fullPage) return spinner

  return <div className="spinner-page">{spinner}</div>
}
