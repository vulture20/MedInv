import { NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

/**
 * Administration area (briefing 15.), split into one page per concern
 * (Users, Plugins, Backups, Mail, Settings) behind a shared tab strip —
 * this used to be a single AdminPage with everything in one scroll, which
 * got unwieldy once mail/backup-schedule/security settings needed forms of
 * their own (see the individual pages under pages/admin/).
 */
export function AdminLayout() {
  const { t } = useTranslation()

  return (
    <div>
      <h1>{t('admin.title')}</h1>
      <nav className="admin-tabs" aria-label={t('admin.title')}>
        <NavLink to="users">{t('admin.users')}</NavLink>
        <NavLink to="plugins">{t('admin.plugins')}</NavLink>
        <NavLink to="backups">{t('admin.backups')}</NavLink>
        <NavLink to="export-import">{t('admin.exportImport')}</NavLink>
        <NavLink to="mail">{t('admin.mail')}</NavLink>
        <NavLink to="languages">{t('admin.languages')}</NavLink>
        <NavLink to="settings">{t('admin.settings')}</NavLink>
      </nav>
      <Outlet />
    </div>
  )
}
