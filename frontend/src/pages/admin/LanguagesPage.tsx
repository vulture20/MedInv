import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'
import { AVAILABLE_LANGUAGES, BUNDLED_TRANSLATIONS, registerLanguagePack, unregisterLanguagePack } from '../../i18n'
import { setRuntimeLanguagePacks, type LanguagePackSummary } from '../../i18n/languagePackEvents'

interface FullLanguagePack extends LanguagePackSummary {
  translations: object
}

interface BundledPack extends LanguagePackSummary {
  installed: boolean
}

const emptyNewPack = { code: '', name: '', translationsText: '' }

/**
 * Admin CRUD for language packs beyond the bundled German/English
 * (briefing 11.4/17., GitHub issue #15 — backend enforcement is #12).
 * `translations` is sourced from an uploaded JSON file (`<input
 * type="file">`, read via File.text()), deliberately not a field-by-field
 * form: 11.4 explicitly describes the format as editable "mit einem
 * einfachen Texteditor", and the key set mirrors locales/de.json's, which
 * has far too many keys for a generated form to be worth building. A raw
 * textarea was tried first, but pasting a translations blob that size
 * directly into the page was worse than editing it in a real editor and
 * uploading the result — especially paired with the "Download" button
 * every row already has (built-in and admin-created alike), which turns
 * editing an existing pack into a clean download → edit locally → re-upload
 * round trip instead. Every successful create/update/delete here also
 * updates the live i18next instance (registerLanguagePack()/
 * unregisterLanguagePack()) and republishes the runtime-pack list
 * (setRuntimeLanguagePacks()) so a pack an admin just touched is
 * immediately reflected in this same tab — e.g. SettingsPage.tsx's
 * language <select> — without a full reload.
 *
 * Also offers the repo-shipped languagepacks/*.json packs for one-click
 * (re)install (BundledLanguagePackRegistry) — these are already
 * pre-installed on a fresh instance (DatabaseSeeder), so this section is
 * mainly for reinstalling one an admin deleted, or picking up a new bundled
 * pack a later image update ships without needing a restart.
 *
 * The table itself also lists the two build-compiled languages (de/en,
 * AVAILABLE_LANGUAGES) alongside the real language_packs rows, view- and
 * download-only — they're compiled into the frontend build
 * (i18n/index.ts's BUNDLED_TRANSLATIONS), not database rows, so there's
 * nothing to send a PUT/DELETE for in the first place.
 *
 * Card layout matches SystemSettingsPage.tsx's/MailPage.tsx's (.panel-page/
 * .panel-card/.panel-select, see index.css's shared docblock) — one card
 * per concern (bundled packs, the installed-packs table, the create form).
 * No .panel-page__header: see SystemSettingsPage.tsx's docblock for why
 * (AdminLayout.tsx's <Outlet/> already supplies the page title/tab strip);
 * each card's own <h2> carries the section's heading instead, reusing the
 * admin.languages nav label for the table card the way MailPage.tsx reuses
 * admin.mail for its own main card. The installed-packs table itself stays
 * a plain, dense table (same "compare magnitude"-adjacent reasoning as
 * LibraryDetailPage.tsx's media-item list, see its docblock) rather than a
 * card per row.
 */
export function LanguagesPage() {
  const { t } = useTranslation()
  const [packs, setPacks] = useState<LanguagePackSummary[]>([])
  const [loading, setLoading] = useState(true)
  // GitHub issue #110 — previously missing entirely: a failed load() left
  // `loading` stuck at `true` forever (setLoading(false) never ran), the
  // same gap already fixed on several other pages this session.
  const [loadError, setLoadError] = useState<string | null>(null)

  const [bundled, setBundled] = useState<BundledPack[]>([])
  const [loadBundledError, setLoadBundledError] = useState<string | null>(null)
  const [installingCode, setInstallingCode] = useState<string | null>(null)
  const [installError, setInstallError] = useState<string | null>(null)

  const [newPack, setNewPack] = useState(emptyNewPack)
  const [createError, setCreateError] = useState<string | null>(null)

  const [editingCode, setEditingCode] = useState<string | null>(null)
  const [editName, setEditName] = useState('')
  const [editTranslationsText, setEditTranslationsText] = useState('')
  const [editError, setEditError] = useState<string | null>(null)

  const [viewingBuiltIn, setViewingBuiltIn] = useState<(typeof AVAILABLE_LANGUAGES)[number] | null>(null)

  async function load() {
    setLoadError(null)
    try {
      const { data } = await apiClient.get<LanguagePackSummary[]>('/languages')
      setPacks(data)
      setRuntimeLanguagePacks(data)
    } catch (err) {
      setLoadError(describeError(err, t))
    } finally {
      setLoading(false)
    }
  }

  async function loadBundled() {
    try {
      const { data } = await apiClient.get<BundledPack[]>('/admin/languages/bundled')
      setBundled(data)
    } catch (err) {
      setLoadBundledError(describeError(err, t))
    }
  }

  useEffect(() => {
    void load()
    void loadBundled()
    // eslint-disable-next-line react-hooks/exhaustive-deps
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

  /** Reads the chosen file's text content, used by both the create form's and an edit row's file input. */
  async function readUploadedFile(e: React.ChangeEvent<HTMLInputElement>): Promise<string | null> {
    const file = e.target.files?.[0]
    if (!file) return null
    return file.text()
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
      await loadBundled()
    } catch (err) {
      window.alert(describePackError(err))
    }
  }

  async function installBundled(pack: BundledPack) {
    // install() always overwrites (BundledLanguagePackRegistry::install()'s
    // docblock) — confirm before clobbering an admin's own edits to an
    // already-installed pack. A not-yet-installed pack needs no confirmation.
    if (pack.installed && !window.confirm(t('admin.languagesPage.confirmReinstall', { name: pack.name }))) return

    setInstallError(null)
    setInstallingCode(pack.code)
    try {
      const { data } = await apiClient.post<FullLanguagePack>(`/admin/languages/bundled/${pack.code}`)
      registerLanguagePack(data.code, data.translations)
      await load()
      await loadBundled()
    } catch (err) {
      setInstallError(describePackError(err))
    } finally {
      setInstallingCode(null)
    }
  }

  /**
   * de/en never leave the browser via the API (they're already fully
   * present client-side, see BUNDLED_TRANSLATIONS) — no server round trip
   * needed, unlike the real export flow (ExportImportPage.tsx), which has
   * to ask the backend for data it doesn't have on its own.
   */
  function downloadBuiltIn(code: (typeof AVAILABLE_LANGUAGES)[number]) {
    const payload = { code, name: t(`settings.language.${code}`), translations: BUNDLED_TRANSLATIONS[code] }
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `medinv-language-${code}.json`
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="panel-page">
      <section className="panel-card">
        <h2>{t('admin.languagesPage.bundledTitle')}</h2>
        {loadBundledError && <p role="alert">{loadBundledError}</p>}
        <ul className="bundled-language-list">
          {bundled.map((pack) => (
            <li key={pack.code} className="bundled-language-list__row">
              <span>
                {pack.name} <span className="hint">({pack.code})</span>
                {pack.installed && <span className="hint"> — {t('admin.languagesPage.bundledInstalled')}</span>}
              </span>
              <button onClick={() => void installBundled(pack)} disabled={installingCode === pack.code}>
                {pack.installed ? t('admin.languagesPage.bundledReinstall') : t('admin.languagesPage.bundledInstall')}
              </button>
            </li>
          ))}
        </ul>
        {installError && <p role="alert">{installError}</p>}
      </section>

      <section className="panel-card">
        <h2>{t('admin.languages')}</h2>
        <p className="hint">{t('admin.languagesPage.builtInHint')}</p>
        {loadError && <p role="alert">{loadError}</p>}

        {loading ? (
          <p className="hint">…</p>
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
              {AVAILABLE_LANGUAGES.map((code) =>
                viewingBuiltIn === code ? (
                  <tr key={code}>
                    <td>{code}</td>
                    <td>
                      <textarea rows={10} readOnly value={JSON.stringify(BUNDLED_TRANSLATIONS[code], null, 2)} />
                    </td>
                    <td>
                      <button onClick={() => setViewingBuiltIn(null)}>{t('admin.actions.close')}</button>
                    </td>
                  </tr>
                ) : (
                  <tr key={code}>
                    <td>{code}</td>
                    <td>{t(`settings.language.${code}`)}</td>
                    <td>
                      <button onClick={() => setViewingBuiltIn(code)}>{t('admin.actions.view')}</button>
                      <button onClick={() => downloadBuiltIn(code)}>{t('admin.actions.download')}</button>
                    </td>
                  </tr>
                ),
              )}
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
                      <input className="panel-select" value={editName} onChange={(e) => setEditName(e.target.value)} required />
                      <label>
                        {t('admin.languagesPage.translations')}
                        <input
                          type="file"
                          accept=".json,application/json"
                          onChange={(e) => void readUploadedFile(e).then((text) => text !== null && setEditTranslationsText(text))}
                        />
                      </label>
                      <p className="hint">{t('admin.languagesPage.editFileHint')}</p>
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
      </section>

      <section className="panel-card">
        <h2>{t('admin.languagesPage.create')}</h2>
        <form onSubmit={createPack}>
          <label>
            {t('admin.languagesPage.code')}
            <input
              className="panel-select"
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
              className="panel-select"
              value={newPack.name}
              onChange={(e) => setNewPack({ ...newPack, name: e.target.value })}
              placeholder="Français"
              required
            />
          </label>
          <p className="hint">{t('admin.languagesPage.translationsHint')}</p>
          <label>
            {t('admin.languagesPage.translations')}
            <input
              type="file"
              accept=".json,application/json"
              onChange={(e) => void readUploadedFile(e).then((text) => text !== null && setNewPack({ ...newPack, translationsText: text }))}
              required
            />
          </label>
          <button type="submit">{t('admin.languagesPage.create')}</button>
          {createError && <p role="alert">{createError}</p>}
        </form>
      </section>
    </div>
  )
}
