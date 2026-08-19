import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'

interface AdminUser {
  id: number
  name: string
  email: string
  level: 'guest' | 'user' | 'admin'
  is_active: boolean
  is_protected: boolean
}

interface CreateUserResponse extends AdminUser {
  invite_sent?: boolean
  invite_error?: string | null
}

const emptyNewUser = {
  name: '',
  email: '',
  password: '',
  level: 'user' as AdminUser['level'],
  sendInvite: false,
}

/**
 * User management (briefing 15.): list, create, edit, (de)activate, delete.
 *
 * Card layout matches LanguagesPage.tsx's/TemplatesPage.tsx's (.panel-page/
 * .panel-card/.panel-field/.panel-select, see index.css's shared
 * docblock) — a card for the users table (kept a plain, dense table
 * rather than a card per row, same reasoning as LanguagesPage.tsx's), a
 * card for the create-user form. The "send invite" checkbox uses
 * .panel-field (see its own docblock) so it sits on its own row like
 * every other field here, rather than only being forced onto one by
 * whatever happens to precede or follow it.
 */
export function UsersPage() {
  const { t } = useTranslation()
  const { mailServerHealthy } = useAuth()
  const [users, setUsers] = useState<AdminUser[]>([])
  const [newUser, setNewUser] = useState(emptyNewUser)
  const [createUserError, setCreateUserError] = useState<string | null>(null)
  const [inviteStatus, setInviteStatus] = useState<string | null>(null)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editUser, setEditUser] = useState<{ name: string; email: string; level: AdminUser['level'] } | null>(null)
  const [editError, setEditError] = useState<string | null>(null)

  // GitHub issue #110 — previously missing entirely: a failed request left
  // the user table silently empty with no indication anything went wrong.
  // window.alert(), not an inline message — same convention
  // toggleActive()/deleteUser() below already use for a failure against
  // this same list.
  async function loadUsers() {
    try {
      const { data } = await apiClient.get<AdminUser[]>('/admin/users')
      setUsers(data)
    } catch (err) {
      window.alert(describeError(err, t))
    }
  }

  useEffect(() => {
    void loadUsers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
    setInviteStatus(null)
    try {
      const { name, email, password, level, sendInvite } = newUser
      const { data } = await apiClient.post<CreateUserResponse>('/admin/users', {
        name,
        email,
        password,
        level,
        send_invite: sendInvite,
      })
      if (sendInvite) {
        setInviteStatus(data.invite_sent ? t('admin.userInvite.sent') : t('admin.userInvite.failed'))
      }
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

  return (
    <div className="panel-page panel-page--wide">
      <section className="panel-card">
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
                    <input className="panel-select" value={editUser.name} onChange={(e) => setEditUser({ ...editUser, name: e.target.value })} />
                  </td>
                  <td>
                    <input
                      className="panel-select"
                      type="email"
                      value={editUser.email}
                      onChange={(e) => setEditUser({ ...editUser, email: e.target.value })}
                    />
                  </td>
                  <td>
                    <select
                      className="panel-select"
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
      </section>

      <section className="panel-card">
        <h2>{t('admin.actions.createUser')}</h2>
        <form onSubmit={createUser}>
          <label>
            {t('common.name')}
            <input className="panel-select" value={newUser.name} onChange={(e) => setNewUser({ ...newUser, name: e.target.value })} required />
          </label>
          <label>
            {t('common.email')}
            <input
              className="panel-select"
              type="email"
              value={newUser.email}
              onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
              required
            />
          </label>
          <label>
            {t('login.password')}
            <input
              className="panel-select"
              type="password"
              value={newUser.password}
              onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
              required
            />
          </label>
          {/* Always visible, not just on error — MedInvPasswordPolicy rejects most
              passwords admins would naturally try otherwise (briefing 12.1). */}
          <p className="hint">{t('admin.passwordHint')}</p>
          <label>
            {t('admin.table.level')}
            <select
              className="panel-select"
              value={newUser.level}
              onChange={(e) => setNewUser({ ...newUser, level: e.target.value as AdminUser['level'] })}
            >
              <option value="guest">guest</option>
              <option value="user">user</option>
              <option value="admin">admin</option>
            </select>
          </label>
          <label className="panel-field">
            <input
              type="checkbox"
              checked={newUser.sendInvite}
              disabled={!mailServerHealthy}
              onChange={(e) => setNewUser({ ...newUser, sendInvite: e.target.checked })}
            />
            {t('admin.actions.sendInvite')}
          </label>
          {/* Same gate as LoginPage's "forgot password" link (briefing 12.2) — sending an
              invite that can only fail isn't worth offering. */}
          {!mailServerHealthy && <p className="hint">{t('admin.userInvite.hint')}</p>}
          <button type="submit">{t('admin.actions.createUser')}</button>
          {createUserError && <p role="alert">{createUserError}</p>}
          {inviteStatus && (
            <p role="status" className="panel-confirmation">
              {inviteStatus}
            </p>
          )}
        </form>
      </section>
    </div>
  )
}
