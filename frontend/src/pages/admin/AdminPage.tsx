import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'

interface AdminUser {
  id: number
  name: string
  email: string
  level: 'guest' | 'user' | 'admin'
  is_active: boolean
  is_protected: boolean
}

interface Backup {
  id: number
  filename: string
  size_bytes: number
  trigger: string
  status: string
  created_at: string
}

const emptyNewUser = { name: '', email: '', password: '', level: 'user' as AdminUser['level'] }

/**
 * Turns an API error into a message worth showing the admin, instead of the
 * request just failing silently (a real bug: creating a user with a
 * policy-violating password 422ed with no feedback at all — the caller
 * simply never awaited/caught the rejection). Known `error_code`s get their
 * translated string; Laravel's default `{errors: {field: [messages]}}`
 * validation shape gets a translated hint for the password policy
 * specifically (the most common cause) or the raw field messages
 * otherwise; anything else falls back to the generic translation.
 */
function describeError(err: unknown, t: TFunction): string {
  if (!isAxiosError(err)) return t('errors.generic')

  const data = err.response?.data as { error_code?: string; errors?: Record<string, string[]> } | undefined
  if (data?.error_code === 'protected_account') return t('admin.errors.protected_account')
  if (data?.errors) {
    if (data.errors.password) return t('admin.errors.passwordPolicy')

    return Object.values(data.errors).flat().join(' ')
  }

  return t('errors.generic')
}

/**
 * Administration area (briefing 15.). One page with sections rather than
 * separate routes, kept simple for the scaffold — split into
 * pages/admin/{Users,Plugins,Backups,Settings}.tsx + sub-routing once each
 * section grows (mail/backup/security settings forms, plugin toggle UI,
 * etc. are all TODO beyond the user/backup management wired up here).
 */
export function AdminPage() {
  const { t } = useTranslation()
  const [users, setUsers] = useState<AdminUser[]>([])
  const [backups, setBackups] = useState<Backup[]>([])
  const [newUser, setNewUser] = useState(emptyNewUser)
  const [createUserError, setCreateUserError] = useState<string | null>(null)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editUser, setEditUser] = useState<{ name: string; email: string; level: AdminUser['level'] } | null>(null)
  const [editError, setEditError] = useState<string | null>(null)

  async function loadUsers() {
    const { data } = await apiClient.get<AdminUser[]>('/admin/users')
    setUsers(data)
  }

  async function loadBackups() {
    const { data } = await apiClient.get<Backup[]>('/admin/backups')
    setBackups(data)
  }

  useEffect(() => {
    void loadUsers()
    void loadBackups()
  }, [])

  async function toggleActive(user: AdminUser) {
    try {
      await apiClient.post(`/admin/users/${user.id}/${user.is_active ? 'deactivate' : 'reactivate'}`)
      await loadUsers()
    } catch (err) {
      window.alert(describeError(err, t))
    }
  }

  async function createUser(e: React.FormEvent) {
    e.preventDefault()
    setCreateUserError(null)
    try {
      await apiClient.post('/admin/users', newUser)
      setNewUser(emptyNewUser)
      await loadUsers()
    } catch (err) {
      setCreateUserError(describeError(err, t))
    }
  }

  function startEdit(user: AdminUser) {
    setEditingId(user.id)
    setEditUser({ name: user.name, email: user.email, level: user.level })
    setEditError(null)
  }

  async function saveEdit(id: number) {
    if (!editUser) return
    setEditError(null)
    try {
      await apiClient.put(`/admin/users/${id}`, editUser)
      setEditingId(null)
      setEditUser(null)
      await loadUsers()
    } catch (err) {
      setEditError(describeError(err, t))
    }
  }

  async function deleteUser(user: AdminUser) {
    if (!window.confirm(t('admin.confirmDeleteUser', { name: user.name }))) return
    try {
      await apiClient.delete(`/admin/users/${user.id}`)
      await loadUsers()
    } catch (err) {
      window.alert(describeError(err, t))
    }
  }

  async function createBackup() {
    await apiClient.post('/admin/backups')
    await loadBackups()
  }

  async function deleteBackup(backup: Backup) {
    if (!window.confirm(t('admin.confirmDeleteBackup', { filename: backup.filename }))) return
    await apiClient.delete(`/admin/backups/${backup.id}`)
    await loadBackups()
  }

  return (
    <div>
      <h1>{t('admin.title')}</h1>

      <section>
        <h2>{t('admin.users')}</h2>
        <table>
          <thead>
            <tr>
              <th>{t('common.name')}</th>
              <th>{t('common.email')}</th>
              <th>{t('admin.table.level')}</th>
              <th>{t('admin.table.status')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {users.map((u) =>
              editingId === u.id && editUser ? (
                <tr key={u.id}>
                  <td>
                    <input value={editUser.name} onChange={(e) => setEditUser({ ...editUser, name: e.target.value })} />
                  </td>
                  <td>
                    <input
                      type="email"
                      value={editUser.email}
                      onChange={(e) => setEditUser({ ...editUser, email: e.target.value })}
                    />
                  </td>
                  <td>
                    <select
                      value={editUser.level}
                      onChange={(e) => setEditUser({ ...editUser, level: e.target.value as AdminUser['level'] })}
                    >
                      <option value="guest">guest</option>
                      <option value="user">user</option>
                      <option value="admin">admin</option>
                    </select>
                  </td>
                  <td>{u.is_active ? t('admin.status.active') : t('admin.status.deactivated')}</td>
                  <td>
                    <button onClick={() => void saveEdit(u.id)}>{t('admin.actions.save')}</button>
                    <button
                      onClick={() => {
                        setEditingId(null)
                        setEditUser(null)
                        setEditError(null)
                      }}
                    >
                      {t('admin.actions.cancel')}
                    </button>
                    {editError && <p role="alert">{editError}</p>}
                  </td>
                </tr>
              ) : (
                <tr key={u.id}>
                  <td>{u.name}</td>
                  <td>{u.email}</td>
                  <td>{u.level}</td>
                  <td>{u.is_active ? t('admin.status.active') : t('admin.status.deactivated')}</td>
                  <td>
                    {/* The predefined admin (is_protected) can never be edited, (de)activated
                        or deleted — see UserController::update()/deactivate()/destroy(). */}
                    {!u.is_protected && (
                      <>
                        <button onClick={() => startEdit(u)}>{t('admin.actions.edit')}</button>
                        <button onClick={() => void toggleActive(u)}>
                          {u.is_active ? t('admin.actions.deactivate') : t('admin.actions.reactivate')}
                        </button>
                        <button onClick={() => void deleteUser(u)}>{t('admin.actions.delete')}</button>
                      </>
                    )}
                  </td>
                </tr>
              ),
            )}
          </tbody>
        </table>

        <h3>{t('admin.actions.createUser')}</h3>
        <form onSubmit={createUser}>
          <label>
            {t('common.name')}
            <input value={newUser.name} onChange={(e) => setNewUser({ ...newUser, name: e.target.value })} required />
          </label>
          <label>
            {t('common.email')}
            <input
              type="email"
              value={newUser.email}
              onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
              required
            />
          </label>
          <label>
            {t('login.password')}
            <input
              type="password"
              value={newUser.password}
              onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
              required
            />
          </label>
          {/* Always visible, not just on error — MedInvPasswordPolicy rejects most
              passwords admins would naturally try otherwise (briefing 12.1). */}
          <p>{t('admin.passwordHint')}</p>
          <label>
            {t('admin.table.level')}
            <select
              value={newUser.level}
              onChange={(e) => setNewUser({ ...newUser, level: e.target.value as AdminUser['level'] })}
            >
              <option value="guest">guest</option>
              <option value="user">user</option>
              <option value="admin">admin</option>
            </select>
          </label>
          <button type="submit">{t('admin.actions.createUser')}</button>
          {createUserError && <p role="alert">{createUserError}</p>}
        </form>
      </section>

      <section>
        <h2>{t('admin.backups')}</h2>
        <button onClick={() => void createBackup()}>{t('admin.actions.createBackupNow')}</button>
        <ul>
          {backups.map((b) => (
            <li key={b.id}>
              {b.filename} — {(b.size_bytes / 1024).toFixed(1)} KB — {b.trigger} — {b.status}{' '}
              <a href={`${apiClient.defaults.baseURL}/admin/backups/${b.id}/download`}>{t('admin.actions.download')}</a>{' '}
              <button onClick={() => void deleteBackup(b)}>{t('admin.actions.delete')}</button>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}
