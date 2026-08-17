import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiClient } from '../../api/client'
import { describeError } from '../admin/adminErrors'
import type { Library, ShareableUser } from './LibraryDetailPage'

interface Props {
  library: Library
  shareableUsers: ShareableUser[]
  open: boolean
  onClose: () => void
  /** Called after any successful save below (name/description, shares, or ownership) so LibraryDetailPage re-fetches the library — canManage, the item list's owner display, etc. all depend on fresh data. */
  onSaved: () => void
}

/**
 * Library name/description editing, sharing (briefing 4.3, GitHub issue
 * #32) and ownership transfer (GitHub issue #34), all behind the single
 * "Bearbeiten" button (GitHub issue #76) instead of three separate,
 * always-visible page sections (previously two of the three even needed
 * their own second "Rechte bearbeiten" button per the issue's original
 * proposal — folded into this one dialog instead per follow-up feedback).
 * Same native-<dialog> pattern as CreateMediaItemDialog.tsx/
 * MediaItemDetailDialog.tsx.
 *
 * Each section keeps its own form/save button, submitting straight to its
 * own endpoint (PUT .../{id}, .../shares, .../owner) exactly as before —
 * only the container changed from three <section>s to three fieldsets
 * inside one dialog, not the three independent save actions themselves.
 */
export function LibrarySettingsDialog({ library, shareableUsers, open, onClose, onSaved }: Props) {
  const { t } = useTranslation()
  const dialogRef = useRef<HTMLDialogElement>(null)

  const [editName, setEditName] = useState('')
  const [editDescription, setEditDescription] = useState('')
  const [infoError, setInfoError] = useState<string | null>(null)

  const [guestShare, setGuestShare] = useState(false)
  const [allUsersShare, setAllUsersShare] = useState(false)
  const [userShares, setUserShares] = useState<{ user_id: number; name: string }[]>([])
  const [addUserId, setAddUserId] = useState<number | ''>('')
  const [sharesSaved, setSharesSaved] = useState(false)
  const [sharesError, setSharesError] = useState<string | null>(null)

  const [newOwnerId, setNewOwnerId] = useState<number | ''>('')
  const [ownerTransferError, setOwnerTransferError] = useState<string | null>(null)

  // Resets every field from the current `library` only when the dialog
  // actually opens (deps: [open], not [open, library]) — a mid-visit
  // onSaved() (e.g. saving shares) reloads `library` in the parent and
  // hands down a new object here, but re-running this on every such change
  // would stomp an unrelated field the admin is still mid-edit on (e.g. a
  // typed-but-unsaved name change) right after an unrelated section's
  // save. Mirrors CreateMediaItemDialog's own reset-on-open effect.
  useEffect(() => {
    if (open) {
      setEditName(library.name)
      setEditDescription(library.description ?? '')
      setInfoError(null)
      setGuestShare(library.shares?.some((s) => s.scope === 'guest') ?? false)
      setAllUsersShare(library.shares?.some((s) => s.scope === 'all_users') ?? false)
      setUserShares(
        (library.shares ?? [])
          .filter((s) => s.scope === 'user' && s.user)
          .map((s) => ({ user_id: s.user!.id, name: s.user!.name })),
      )
      setAddUserId('')
      setSharesSaved(false)
      setSharesError(null)
      setNewOwnerId('')
      setOwnerTransferError(null)
      dialogRef.current?.showModal()
    } else {
      dialogRef.current?.close()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const usersAvailableToAdd = shareableUsers.filter((u) => !userShares.some((s) => s.user_id === u.id))

  async function saveInfo(e: React.FormEvent) {
    e.preventDefault()
    setInfoError(null)
    try {
      await apiClient.put(`/libraries/${library.id}`, {
        name: editName,
        description: editDescription === '' ? null : editDescription,
      })
      onSaved()
    } catch (err) {
      setInfoError(describeError(err, t))
    }
  }

  async function saveShares(e: React.FormEvent) {
    e.preventDefault()
    setSharesError(null)
    setSharesSaved(false)
    const shares = [
      ...(guestShare ? [{ scope: 'guest' }] : []),
      ...(allUsersShare ? [{ scope: 'all_users' }] : []),
      ...userShares.map((s) => ({ scope: 'user', user_id: s.user_id })),
    ]
    try {
      await apiClient.put(`/libraries/${library.id}/shares`, { shares })
      setSharesSaved(true)
      onSaved()
    } catch (err) {
      setSharesError(describeError(err, t))
    }
  }

  function addUserShare() {
    if (addUserId === '') return
    const target = shareableUsers.find((u) => u.id === addUserId)
    if (!target) return
    setUserShares((prev) => [...prev, { user_id: target.id, name: target.name }])
    setAddUserId('')
  }

  async function transferOwnership() {
    if (newOwnerId === '') return
    const target = shareableUsers.find((u) => u.id === newOwnerId)
    if (!target) return
    if (!window.confirm(t('libraries.ownership.confirm', { name: target.name }))) return
    setOwnerTransferError(null)
    try {
      await apiClient.put(`/libraries/${library.id}/owner`, { owner_id: newOwnerId })
      onSaved()
      // The admin closing this dialog themselves out of a library they may
      // no longer manage at all once ownership has moved away from them
      // (unless they're a site admin) reads oddly — close it for them
      // instead of leaving it open on actions they may no longer be
      // allowed to perform.
      onClose()
    } catch (err) {
      setOwnerTransferError(describeError(err, t))
    }
  }

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      // See MediaItemDetailDialog.tsx's identical handler for why this is
      // safe against misfiring on a normal in-dialog click.
      onClick={(e) => e.target === e.currentTarget && onClose()}
      className="media-item-dialog"
    >
      {open && (
        <>
          <h3>{t('admin.actions.edit')}</h3>

          <form onSubmit={(e) => void saveInfo(e)}>
            <label>
              {t('common.name')}
              <input className="panel-select" value={editName} onChange={(e) => setEditName(e.target.value)} required />
            </label>
            <label>
              {t('libraries.descriptionLabel')}
              <textarea className="panel-select" value={editDescription} onChange={(e) => setEditDescription(e.target.value)} />
            </label>
            <button type="submit">{t('admin.actions.save')}</button>
            {infoError && <p role="alert">{infoError}</p>}
          </form>

          <h4>{t('libraries.sharing.title')}</h4>
          <p className="hint">{t('libraries.sharing.hint')}</p>
          <form onSubmit={(e) => void saveShares(e)}>
            <label>
              <input type="checkbox" checked={guestShare} onChange={(e) => setGuestShare(e.target.checked)} />
              {t('libraries.sharing.guests')}
            </label>
            <label>
              <input type="checkbox" checked={allUsersShare} onChange={(e) => setAllUsersShare(e.target.checked)} />
              {t('libraries.sharing.allUsers')}
            </label>

            <div>
              <h5>{t('libraries.sharing.specificUsers')}</h5>
              {userShares.length === 0 ? (
                <p className="hint">{t('libraries.sharing.noSpecificUsers')}</p>
              ) : (
                <ul>
                  {userShares.map((share) => (
                    <li key={share.user_id}>
                      {share.name}{' '}
                      <button
                        type="button"
                        onClick={() => setUserShares((prev) => prev.filter((s) => s.user_id !== share.user_id))}
                      >
                        {t('libraries.sharing.remove')}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {usersAvailableToAdd.length > 0 && (
                <p>
                  <select className="panel-select" value={addUserId} onChange={(e) => setAddUserId(e.target.value ? Number(e.target.value) : '')}>
                    <option value="">{t('libraries.sharing.selectUser')}</option>
                    {usersAvailableToAdd.map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name}
                      </option>
                    ))}
                  </select>{' '}
                  <button type="button" disabled={addUserId === ''} onClick={addUserShare}>
                    {t('libraries.sharing.add')}
                  </button>
                </p>
              )}
            </div>

            <button type="submit">{t('admin.actions.save')}</button>
            {sharesSaved && (
              <p role="status" className="panel-confirmation">
                {t('libraries.sharing.saved')}
              </p>
            )}
            {sharesError && <p role="alert">{sharesError}</p>}
          </form>

          {shareableUsers.length > 0 && (
            <>
              <h4>{t('libraries.ownership.title')}</h4>
              <p className="hint">{t('libraries.ownership.hint')}</p>
              <p>
                <select className="panel-select" value={newOwnerId} onChange={(e) => setNewOwnerId(e.target.value ? Number(e.target.value) : '')}>
                  <option value="">{t('libraries.ownership.selectUser')}</option>
                  {shareableUsers.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name}
                    </option>
                  ))}
                </select>{' '}
                <button type="button" disabled={newOwnerId === ''} onClick={() => void transferOwnership()}>
                  {t('libraries.ownership.transfer')}
                </button>
              </p>
              {ownerTransferError && <p role="alert">{ownerTransferError}</p>}
            </>
          )}

          <div className="media-item-dialog__actions">
            <button type="button" onClick={onClose}>
              {t('admin.actions.close')}
            </button>
          </div>
        </>
      )}
    </dialog>
  )
}
