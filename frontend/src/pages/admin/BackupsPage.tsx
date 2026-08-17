import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface Backup {
  id: number
  filename: string
  size_bytes: number
  trigger: string
  /** Only ever set for trigger='automatic' — which of the two independent automatic paths created it (BackupService::create()'s docblock). */
  reason: 'scheduled' | 'pre_update' | null
  status: string
  created_at: string
}

interface BackupSettings {
  interval_mode: 'daily' | 'weekly' | 'monthly' | 'cron'
  cron_expression: string | null
  /**
   * Exactly one of retention_count/retention_max_age_days is actually
   * enforced (BackupService::prune()) — a deliberate deviation from
   * briefing 9.2's literal wording (both applying at once, simultaneously
   * editable), reported as confusing rather than useful. The inactive
   * field's value is still stored/shown once you switch back, just not
   * applied while the other mode is selected.
   */
  retention_mode: 'count' | 'age'
  retention_count: number | null
  retention_max_age_days: number | null
}

interface RestoreResult {
  created: string[]
  merged: string[]
  overwritten: string[]
  skipped: string[]
  settings_restored: boolean
  users_restored: string[]
}

/**
 * Backups (briefing 9.2): on-demand creation/download/delete plus the
 * schedule/retention config.
 *
 * Card layout matches ExportImportPage.tsx's (.panel-page/.panel-card/
 * .panel-field/.panel-select/.panel-confirmation, see index.css's shared
 * docblock) — a card for the backup list (each backup a dense row, same
 * "compare magnitude"-adjacent reasoning as LibraryDetailPage.tsx's
 * media-item list, plus an inline expandable restore panel per row), a
 * card for the schedule/retention form. The restore panel's two checkbox
 * labels use .panel-field for the same reason ExportImportPage.tsx's do
 * (see that class's docblock) — two checkbox-only labels in a row would
 * otherwise run into each other.
 */
export function BackupsPage() {
  const { t } = useTranslation()
  const [backups, setBackups] = useState<Backup[]>([])
  const [settings, setSettings] = useState<BackupSettings | null>(null)
  const [settingsError, setSettingsError] = useState<string | null>(null)
  const [settingsSaved, setSettingsSaved] = useState(false)

  // Which backup's restore form is expanded, and its two options — briefing 9.3's
  // full per-library rename/merge/overwrite/skip picker needs to know which
  // libraries the backup actually contains, which nothing currently exposes
  // ahead of attempting the restore; "overwrite every conflicting library" (via
  // BackupController::restore()'s `conflict_resolutions.__default__`, the same
  // sentinel Console\Commands\RestoreBackupOnBoot uses) keeps this usable without
  // that. A library not present in the backup at all is never touched either way.
  const [restoringId, setRestoringId] = useState<number | null>(null)
  const [overwriteExisting, setOverwriteExisting] = useState(false)
  const [restoreSettings, setRestoreSettings] = useState(false)
  const [restoreResult, setRestoreResult] = useState<string | null>(null)
  const [restoreError, setRestoreError] = useState<string | null>(null)

  async function loadBackups() {
    const { data } = await apiClient.get<Backup[]>('/admin/backups')
    setBackups(data)
  }

  async function loadSettings() {
    const { data } = await apiClient.get<{ backup: BackupSettings }>('/admin/settings')
    setSettings(data.backup)
  }

  useEffect(() => {
    void loadBackups()
    void loadSettings()
  }, [])

  async function createBackup() {
    await apiClient.post('/admin/backups')
    await loadBackups()
  }

  async function deleteBackup(backup: Backup) {
    if (!window.confirm(t('admin.confirmDeleteBackup', { filename: backup.filename }))) return
    await apiClient.delete(`/admin/backups/${backup.id}`)
    await loadBackups()
  }

  function startRestore(backup: Backup) {
    setRestoringId(backup.id)
    setOverwriteExisting(false)
    setRestoreSettings(false)
    setRestoreResult(null)
    setRestoreError(null)
  }

  async function confirmRestore(backup: Backup) {
    if (!window.confirm(t('admin.backupRestore.confirm', { filename: backup.filename }))) return
    setRestoreError(null)
    try {
      const { data } = await apiClient.post<RestoreResult>(`/admin/backups/${backup.id}/restore`, {
        conflict_resolutions: overwriteExisting ? { __default__: 'overwrite' } : {},
        restore_settings: restoreSettings,
      })
      setRestoreResult(
        t('admin.backupRestore.success', {
          created: data.created.length,
          overwritten: data.overwritten.length,
          merged: data.merged.length,
          skipped: data.skipped.length,
        }),
      )
      setRestoringId(null)
    } catch (err) {
      setRestoreError(describeError(err, t))
    }
  }

  async function saveSettings(e: React.FormEvent) {
    e.preventDefault()
    if (!settings) return
    setSettingsError(null)
    setSettingsSaved(false)
    try {
      const { data } = await apiClient.put<BackupSettings>('/admin/settings/backup', settings)
      setSettings(data)
      setSettingsSaved(true)
    } catch (err) {
      setSettingsError(describeError(err, t))
    }
  }

  return (
    <div className="panel-page">
      <section className="panel-card">
        <h2>{t('admin.backups')}</h2>
        <button onClick={() => void createBackup()}>{t('admin.actions.createBackupNow')}</button>
        {backups.length === 0 ? (
          <p className="hint">{t('admin.noBackups')}</p>
        ) : (
          <ul className="backup-list">
            {backups.map((b) => (
              <li key={b.id} className="backup-list__row">
                <div className="backup-list__header">
                  <div className="backup-list__info">
                    <strong>{b.filename}</strong>
                    <span className="hint">
                      {(b.size_bytes / 1024).toFixed(1)} KB — {b.trigger}
                      {b.reason && ` (${t(`admin.backupReason.${b.reason}`)})`} — {b.status}
                    </span>
                  </div>
                  <div className="backup-list__actions">
                    <button
                      type="button"
                      onClick={() => {
                        window.location.href = `${apiClient.defaults.baseURL}/admin/backups/${b.id}/download`
                      }}
                    >
                      {t('admin.actions.download')}
                    </button>
                    <button onClick={() => void deleteBackup(b)}>{t('admin.actions.delete')}</button>
                    {restoringId === b.id ? (
                      <button onClick={() => setRestoringId(null)}>{t('admin.actions.cancel')}</button>
                    ) : (
                      <button onClick={() => startRestore(b)}>{t('admin.actions.restore')}</button>
                    )}
                  </div>
                </div>
                {restoringId === b.id && (
                  <div className="backup-restore">
                    <p className="hint">{t('admin.backupRestore.warning')}</p>
                    <label className="panel-field">
                      <input
                        type="checkbox"
                        checked={overwriteExisting}
                        onChange={(e) => setOverwriteExisting(e.target.checked)}
                      />
                      {t('admin.backupRestore.overwriteExisting')}
                    </label>
                    <label className="panel-field">
                      <input
                        type="checkbox"
                        checked={restoreSettings}
                        onChange={(e) => setRestoreSettings(e.target.checked)}
                      />
                      {t('admin.backupRestore.restoreSettings')}
                    </label>
                    <button onClick={() => void confirmRestore(b)}>{t('admin.backupRestore.submit')}</button>
                    {restoreError && <p role="alert">{restoreError}</p>}
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
        {restoreResult && (
          <p role="status" className="panel-confirmation">
            {restoreResult}
          </p>
        )}
      </section>

      {settings && (
        <section className="panel-card">
          <h2>{t('admin.backupSettings.title')}</h2>
          <p className="hint">{t('admin.backupSettings.retentionHint')}</p>
          <form onSubmit={saveSettings}>
            <label>
              {t('admin.backupSettings.intervalMode')}
              <select
                className="panel-select"
                value={settings.interval_mode}
                onChange={(e) =>
                  setSettings({ ...settings, interval_mode: e.target.value as BackupSettings['interval_mode'] })
                }
              >
                <option value="daily">{t('admin.backupSettings.interval.daily')}</option>
                <option value="weekly">{t('admin.backupSettings.interval.weekly')}</option>
                <option value="monthly">{t('admin.backupSettings.interval.monthly')}</option>
                <option value="cron">{t('admin.backupSettings.interval.cron')}</option>
              </select>
            </label>
            {settings.interval_mode === 'cron' && (
              <label>
                {t('admin.backupSettings.cronExpression')}
                <input
                  className="panel-select"
                  value={settings.cron_expression ?? ''}
                  onChange={(e) => setSettings({ ...settings, cron_expression: e.target.value })}
                  required
                />
              </label>
            )}
            <fieldset>
              <legend>{t('admin.backupSettings.retentionMode')}</legend>
              <label>
                <input
                  type="radio"
                  name="retention_mode"
                  checked={settings.retention_mode === 'count'}
                  onChange={() => setSettings({ ...settings, retention_mode: 'count' })}
                />
                {t('admin.backupSettings.retentionCount')}
                <input
                  className="panel-select"
                  type="number"
                  min={1}
                  disabled={settings.retention_mode !== 'count'}
                  value={settings.retention_count ?? ''}
                  onChange={(e) =>
                    setSettings({ ...settings, retention_count: e.target.value === '' ? null : Number(e.target.value) })
                  }
                />
              </label>
              <label>
                <input
                  type="radio"
                  name="retention_mode"
                  checked={settings.retention_mode === 'age'}
                  onChange={() => setSettings({ ...settings, retention_mode: 'age' })}
                />
                {t('admin.backupSettings.retentionMaxAgeDays')}
                <input
                  className="panel-select"
                  type="number"
                  min={1}
                  disabled={settings.retention_mode !== 'age'}
                  value={settings.retention_max_age_days ?? ''}
                  onChange={(e) =>
                    setSettings({
                      ...settings,
                      retention_max_age_days: e.target.value === '' ? null : Number(e.target.value),
                    })
                  }
                />
              </label>
            </fieldset>
            <button type="submit">{t('admin.actions.save')}</button>
            {settingsSaved && (
              <p role="status" className="panel-confirmation">
                {t('admin.backupSettings.saved')}
              </p>
            )}
            {settingsError && <p role="alert">{settingsError}</p>}
          </form>
        </section>
      )}
    </div>
  )
}
