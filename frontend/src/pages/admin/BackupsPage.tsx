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

/** Backups (briefing 9.2): on-demand creation/download/delete plus the schedule/retention config. */
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
    <>
      <section>
        <h2>{t('admin.backups')}</h2>
        <button onClick={() => void createBackup()}>{t('admin.actions.createBackupNow')}</button>
        <ul>
          {backups.map((b) => (
            <li key={b.id}>
              {b.filename} — {(b.size_bytes / 1024).toFixed(1)} KB — {b.trigger}
              {b.reason && ` (${t(`admin.backupReason.${b.reason}`)})`} — {b.status}{' '}
              <a href={`${apiClient.defaults.baseURL}/admin/backups/${b.id}/download`}>{t('admin.actions.download')}</a>{' '}
              <button onClick={() => void deleteBackup(b)}>{t('admin.actions.delete')}</button>{' '}
              {restoringId === b.id ? (
                <button onClick={() => setRestoringId(null)}>{t('admin.actions.cancel')}</button>
              ) : (
                <button onClick={() => startRestore(b)}>{t('admin.actions.restore')}</button>
              )}
              {restoringId === b.id && (
                <div>
                  <p>{t('admin.backupRestore.warning')}</p>
                  <label>
                    <input
                      type="checkbox"
                      checked={overwriteExisting}
                      onChange={(e) => setOverwriteExisting(e.target.checked)}
                    />
                    {t('admin.backupRestore.overwriteExisting')}
                  </label>
                  <label>
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
        {restoreResult && <p role="status">{restoreResult}</p>}
      </section>

      {settings && (
        <section>
          <h2>{t('admin.backupSettings.title')}</h2>
          <p className="hint">{t('admin.backupSettings.retentionHint')}</p>
          <form onSubmit={saveSettings}>
            <label>
              {t('admin.backupSettings.intervalMode')}
              <select
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
                  value={settings.cron_expression ?? ''}
                  onChange={(e) => setSettings({ ...settings, cron_expression: e.target.value })}
                  required
                />
              </label>
            )}
            <label>
              {t('admin.backupSettings.retentionCount')}
              <input
                type="number"
                min={1}
                value={settings.retention_count ?? ''}
                onChange={(e) =>
                  setSettings({ ...settings, retention_count: e.target.value === '' ? null : Number(e.target.value) })
                }
              />
            </label>
            <label>
              {t('admin.backupSettings.retentionMaxAgeDays')}
              <input
                type="number"
                min={1}
                value={settings.retention_max_age_days ?? ''}
                onChange={(e) =>
                  setSettings({
                    ...settings,
                    retention_max_age_days: e.target.value === '' ? null : Number(e.target.value),
                  })
                }
              />
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {settingsSaved && <p role="status">{t('admin.backupSettings.saved')}</p>}
            {settingsError && <p role="alert">{settingsError}</p>}
          </form>
        </section>
      )}
    </>
  )
}
