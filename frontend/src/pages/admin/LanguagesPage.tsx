import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'
import { registerLanguagePack, unregisterLanguagePack } from '../../i18n'
import { setRuntimeLanguagePacks, type LanguagePackSummary } from '../../i18n/languagePackEvents'

interface FullLanguagePack extends LanguagePackSummary {
  translations: object
}

const emptyNewPack = { code: '', name: '', translationsText: '' }

/**
 * Admin CRUD for language packs beyond the bundled German/English
 * (briefing 11.4/17., GitHub issue #15 — backend enforcement is #12).
 * `translations` is a raw JSON <textarea>, deliberately not a field-by-field
 * form: 11.4 explicitly describes the format as editable "mit einem
 * einfachen Texteditor", and the key set mirrors locales/de.json's, which
 * has far too many keys for a generated form to be worth building. Every
 * successful create/update/delete here also updates the live i18next
 * instance (registerLanguagePack()/unregisterLanguagePack()) and republishes
 * the runtime-pack list (setRuntimeLanguagePacks()) so a pack an admin just
 * touched is immediately reflected in this same tab — e.g. SettingsPage.tsx's
 * language <select> — without a full reload.
 */
export function LanguagesPage() {
  const { t } = useTranslation()
  const [packs, setPacks] = useState<LanguagePackSummary[]>([])
  const [loading, setLoading] = useState(true)

  const [newPack, setNewPack] = useState(emptyNewPack)
  const [createError, setCreateError] = useState<string | null>(null)

  const [editingCode, setEditingCode] = useState<string | null>(null)
  const [editName, setEditName] = useState('')
  const [editTranslationsText, setEditTranslationsText] = useState('')
  const [editError, setEditError] = useState<string | null>(null)

  async function load() {
    const { data } = await apiClient.get<LanguagePackSummary[]>('/languages')
    setPacks(data)
    setRuntimeLanguagePacks(data)
    setLoading(false)
  }

  useEffect(() => {
    void load()
  }, [])

  /**
   * A validation error on `code` here always means "already taken" or
   * "de/en are reserved" (LanguagePackController::store()'s Rule::notIn) —
   * distinguishing the two from Laravel's raw English validation text would
   * mean brittle string-matching, and both boil down to the same actionable
   * advice, so they share one translated message. Anything else falls back
   * to the shared describeError().
   */
  function describePackError(err: unknown): string {
    if (isAxiosError(err)) {
      const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
      if (errors?.code) return t('admin.languagesPage.errors.codeInvalid')
    }

    return describeError(err, t)
  }

  async function createPack(e: React.FormEvent) {
    e.preventDefault()
    setCreateError(null)

    let translations: object
    try {
      translations = JSON.parse(newPack.translationsText) as object
    } catch {
      setCreateError(t('admin.languagesPage.errors.invalidJson'))
      return
    }

    try {
      const { data } = await apiClient.post<FullLanguagePack>('/admin/languages', {
        code: newPack.code,
        name: newPack.name,
        translations,
      })
      registerLanguagePack(data.code, data.translations)
      setNewPack(emptyNewPack)
      await load()
    } catch (err) {
      setCreateError(describePackError(err))
    }
  }

  async function startEdit(pack: LanguagePackSummary) {
    setEditingCode(pack.code)
    setEditError(null)
    const { data } = await apiClient.get<FullLanguagePack>(`/languages/${pack.code}`)
    setEditName(data.name)
    setEditTranslationsText(JSON.stringify(data.translations, null, 2))
  }

  function cancelEdit() {
    setEditingCode(null)
    setEditError(null)
  }

  async function saveEdit(code: string) {
    setEditError(null)

    let translations: object
    try {
      translations = JSON.parse(editTranslationsText) as object
    } catch {
      setEditError(t('admin.languagesPage.errors.invalidJson'))
      return
    }

    try {
      const { data } = await apiClient.put<FullLanguagePack>(`/admin/languages/${code}`, {
        name: editName,
        translations,
      })
      registerLanguagePack(data.code, data.translations)
      setEditingCode(null)
      await load()
    } catch (err) {
      setEditError(describePackError(err))
    }
  }

  async function deletePack(pack: LanguagePackSummary) {
    if (!window.confirm(t('admin.languagesPage.confirmDelete', { name: pack.name }))) return
    try {
      await apiClient.delete(`/admin/languages/${pack.code}`)
      unregisterLanguagePack(pack.code)
      await load()
    } catch (err) {
      window.alert(describePackError(err))
    }
  }

  return (
    <section>
      <h2>{t('admin.languages')}</h2>

      {loading ? (
        <p>…</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>{t('admin.languagesPage.code')}</th>
              <th>{t('common.name')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {packs.length === 0 && (
              <tr>
                <td colSpan={3}>{t('admin.languagesPage.none')}</td>
              </tr>
            )}
            {packs.map((pack) =>
              editingCode === pack.code ? (
                <tr key={pack.code}>
                  <td>{pack.code}</td>
                  <td>
                    <input value={editName} onChange={(e) => setEditName(e.target.value)} required />
                    <textarea
                      rows={10}
                      value={editTranslationsText}
                      onChange={(e) => setEditTranslationsText(e.target.value)}
                      required
                    />
                  </td>
                  <td>
                    <button onClick={() => void saveEdit(pack.code)}>{t('admin.actions.save')}</button>
                    <button onClick={cancelEdit}>{t('admin.actions.cancel')}</button>
                    {editError && <p role="alert">{editError}</p>}
                  </td>
                </tr>
              ) : (
                <tr key={pack.code}>
                  <td>{pack.code}</td>
                  <td>{pack.name}</td>
                  <td>
                    <button onClick={() => void startEdit(pack)}>{t('admin.actions.edit')}</button>
                    <button onClick={() => void deletePack(pack)}>{t('admin.actions.delete')}</button>
                  </td>
                </tr>
              ),
            )}
          </tbody>
        </table>
      )}

      <h3>{t('admin.languagesPage.create')}</h3>
      <form onSubmit={createPack}>
        <label>
          {t('admin.languagesPage.code')}
          <input
            value={newPack.code}
            onChange={(e) => setNewPack({ ...newPack, code: e.target.value })}
            placeholder="fr"
            maxLength={10}
            required
          />
        </label>
        <label>
          {t('common.name')}
          <input
            value={newPack.name}
            onChange={(e) => setNewPack({ ...newPack, name: e.target.value })}
            placeholder="Français"
            required
          />
        </label>
        <p className="hint">{t('admin.languagesPage.translationsHint')}</p>
        <label>
          {t('admin.languagesPage.translations')}
          <textarea
            rows={10}
            value={newPack.translationsText}
            onChange={(e) => setNewPack({ ...newPack, translationsText: e.target.value })}
            placeholder='{"login": {"title": "..."}, ...}'
            required
          />
        </label>
        <button type="submit">{t('admin.languagesPage.create')}</button>
        {createError && <p role="alert">{createError}</p>}
      </form>
    </section>
  )
}
