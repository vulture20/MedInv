import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'
import { useTheme, type TemplateSummary } from '../../theme/ThemeContext'
import { BUILT_IN_TEMPLATE_CSS } from '../../theme/builtInCss'

interface FullTemplate extends TemplateSummary {
  css: string
}

interface BundledTemplate extends TemplateSummary {
  installed: boolean
}

const BUILT_IN_CODES = ['light', 'dark'] as const

const emptyNewTemplate = { code: '', name: '', css: '' }

/**
 * Admin CRUD for UI templates beyond the bundled light/dark
 * (briefing 10./11.4, GitHub issue #11). Deliberate structural mirror of
 * LanguagesPage.tsx (see that component's own docblock for the shared
 * reasoning: bundled-template install section, built-in light/dark listed
 * view/download-only, every successful create/edit/delete updates the live
 * ThemeContext registry so a template an admin just touched is immediately
 * selectable in this same tab). `css` is sourced from an uploaded .css
 * file (`<input type="file">`, read via File.text()) — same reasoning as
 * LanguagesPage.tsx's identical switch away from a raw textarea: editing a
 * sizeable stylesheet is a real-editor job, and every row already has a
 * "Download" button (built-in and admin-created alike), turning editing an
 * existing template into a clean download → edit locally → re-upload round
 * trip. A template used to be a small fixed set of color-picker fields, but
 * that was replaced with a real CSS text blob so admins get full theming
 * power ("not just color values, complete CSS files") instead of nine
 * hardcoded properties.
 */
export function TemplatesPage() {
  const { t } = useTranslation()
  const { registerTemplate, unregisterTemplate } = useTheme()
  const [templates, setTemplates] = useState<TemplateSummary[]>([])
  const [loading, setLoading] = useState(true)

  const [bundled, setBundled] = useState<BundledTemplate[]>([])
  const [installingCode, setInstallingCode] = useState<string | null>(null)
  const [installError, setInstallError] = useState<string | null>(null)

  const [newTemplate, setNewTemplate] = useState(emptyNewTemplate)
  const [createError, setCreateError] = useState<string | null>(null)

  const [editingCode, setEditingCode] = useState<string | null>(null)
  const [editName, setEditName] = useState('')
  const [editCss, setEditCss] = useState('')
  const [editError, setEditError] = useState<string | null>(null)

  const [viewingBuiltIn, setViewingBuiltIn] = useState<(typeof BUILT_IN_CODES)[number] | null>(null)

  async function load() {
    const { data } = await apiClient.get<TemplateSummary[]>('/templates')
    setTemplates(data)
    setLoading(false)
  }

  async function loadBundled() {
    const { data } = await apiClient.get<BundledTemplate[]>('/admin/templates/bundled')
    setBundled(data)
  }

  useEffect(() => {
    void load()
    void loadBundled()
  }, [])

  /**
   * A validation error on `code` here always means "already taken" or
   * "light/dark are reserved" (TemplateController::store()'s
   * Rule::notIn) — same reasoning as LanguagesPage.tsx's identical helper.
   * A validation error on `css` (empty, or over the length limit) has no
   * special-cased message — describeError()'s generic fallback already
   * prints the field message Laravel returns, which is specific enough on
   * its own.
   */
  function describeTemplateError(err: unknown): string {
    if (isAxiosError(err)) {
      const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
      if (errors?.code) return t('admin.templatesPage.errors.codeInvalid')
    }

    return describeError(err, t)
  }

  /** Reads the chosen file's text content, used by both the create form's and an edit row's file input. */
  async function readUploadedFile(e: React.ChangeEvent<HTMLInputElement>): Promise<string | null> {
    const file = e.target.files?.[0]
    if (!file) return null
    return file.text()
  }

  async function createTemplate(e: React.FormEvent) {
    e.preventDefault()
    setCreateError(null)

    try {
      const { data } = await apiClient.post<FullTemplate>('/admin/templates', {
        code: newTemplate.code,
        name: newTemplate.name,
        css: newTemplate.css,
      })
      registerTemplate(data.code, data.name, data.css)
      setNewTemplate(emptyNewTemplate)
      await load()
    } catch (err) {
      setCreateError(describeTemplateError(err))
    }
  }

  async function startEdit(template: TemplateSummary) {
    setEditingCode(template.code)
    setEditError(null)
    const { data } = await apiClient.get<FullTemplate>(`/templates/${template.code}`)
    setEditName(data.name)
    setEditCss(data.css)
  }

  function cancelEdit() {
    setEditingCode(null)
    setEditError(null)
  }

  async function saveEdit(code: string) {
    setEditError(null)

    try {
      const { data } = await apiClient.put<FullTemplate>(`/admin/templates/${code}`, {
        name: editName,
        css: editCss,
      })
      registerTemplate(data.code, data.name, data.css)
      setEditingCode(null)
      await load()
    } catch (err) {
      setEditError(describeTemplateError(err))
    }
  }

  async function deleteTemplate(template: TemplateSummary) {
    if (!window.confirm(t('admin.templatesPage.confirmDelete', { name: template.name }))) return
    try {
      await apiClient.delete(`/admin/templates/${template.code}`)
      unregisterTemplate(template.code)
      await load()
      await loadBundled()
    } catch (err) {
      window.alert(describeTemplateError(err))
    }
  }

  async function installBundled(template: BundledTemplate) {
    if (template.installed && !window.confirm(t('admin.templatesPage.confirmReinstall', { name: template.name }))) return

    setInstallError(null)
    setInstallingCode(template.code)
    try {
      const { data } = await apiClient.post<FullTemplate>(`/admin/templates/bundled/${template.code}`)
      registerTemplate(data.code, data.name, data.css)
      await load()
      await loadBundled()
    } catch (err) {
      setInstallError(describeTemplateError(err))
    } finally {
      setInstallingCode(null)
    }
  }

  /** Same reasoning as LanguagesPage.tsx's downloadBuiltIn() — light/dark's CSS is already fully present client-side, no server round trip needed. Downloads the raw .css text itself (not wrapped in JSON) so it's directly usable/editable as a real stylesheet. */
  function downloadBuiltIn(code: (typeof BUILT_IN_CODES)[number]) {
    const blob = new Blob([BUILT_IN_TEMPLATE_CSS[code]], { type: 'text/css' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `medinv-template-${code}.css`
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <section>
      <h2>{t('admin.templates')}</h2>

      <h3>{t('admin.templatesPage.bundledTitle')}</h3>
      {bundled.length === 0 ? (
        <p className="hint">{t('admin.templatesPage.bundledNone')}</p>
      ) : (
        <ul className="bundled-language-list">
          {bundled.map((template) => (
            <li key={template.code}>
              {template.name} ({template.code})
              {template.installed && ` — ${t('admin.templatesPage.bundledInstalled')}`}{' '}
              <button onClick={() => void installBundled(template)} disabled={installingCode === template.code}>
                {template.installed ? t('admin.templatesPage.bundledReinstall') : t('admin.templatesPage.bundledInstall')}
              </button>
            </li>
          ))}
        </ul>
      )}
      {installError && <p role="alert">{installError}</p>}

      <p className="hint">{t('admin.templatesPage.builtInHint')}</p>

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
            {BUILT_IN_CODES.map((code) =>
              viewingBuiltIn === code ? (
                <tr key={code}>
                  <td>{code}</td>
                  <td>
                    <textarea rows={10} readOnly value={BUILT_IN_TEMPLATE_CSS[code]} />
                  </td>
                  <td>
                    <button onClick={() => setViewingBuiltIn(null)}>{t('admin.actions.close')}</button>
                  </td>
                </tr>
              ) : (
                <tr key={code}>
                  <td>{code}</td>
                  <td>{t(`settings.template.${code}`)}</td>
                  <td>
                    <button onClick={() => setViewingBuiltIn(code)}>{t('admin.actions.view')}</button>
                    <button onClick={() => downloadBuiltIn(code)}>{t('admin.actions.download')}</button>
                  </td>
                </tr>
              ),
            )}
            {templates.length === 0 && (
              <tr>
                <td colSpan={3}>{t('admin.templatesPage.none')}</td>
              </tr>
            )}
            {templates.map((template) =>
              editingCode === template.code ? (
                <tr key={template.code}>
                  <td>{template.code}</td>
                  <td>
                    <input value={editName} onChange={(e) => setEditName(e.target.value)} required />
                    <label>
                      {t('admin.templatesPage.css')}
                      <input
                        type="file"
                        accept=".css,text/css"
                        onChange={(e) => void readUploadedFile(e).then((text) => text !== null && setEditCss(text))}
                      />
                    </label>
                    <p className="hint">{t('admin.templatesPage.editFileHint')}</p>
                  </td>
                  <td>
                    <button onClick={() => void saveEdit(template.code)}>{t('admin.actions.save')}</button>
                    <button onClick={cancelEdit}>{t('admin.actions.cancel')}</button>
                    {editError && <p role="alert">{editError}</p>}
                  </td>
                </tr>
              ) : (
                <tr key={template.code}>
                  <td>{template.code}</td>
                  <td>{template.name}</td>
                  <td>
                    <button onClick={() => void startEdit(template)}>{t('admin.actions.edit')}</button>
                    <button onClick={() => void deleteTemplate(template)}>{t('admin.actions.delete')}</button>
                  </td>
                </tr>
              ),
            )}
          </tbody>
        </table>
      )}

      <h3>{t('admin.templatesPage.create')}</h3>
      <form onSubmit={createTemplate}>
        <label>
          {t('admin.languagesPage.code')}
          <input
            value={newTemplate.code}
            onChange={(e) => setNewTemplate({ ...newTemplate, code: e.target.value })}
            placeholder="solarized"
            maxLength={20}
            required
          />
        </label>
        <label>
          {t('common.name')}
          <input
            value={newTemplate.name}
            onChange={(e) => setNewTemplate({ ...newTemplate, name: e.target.value })}
            placeholder="Solarized"
            required
          />
        </label>
        <p className="hint">{t('admin.templatesPage.cssHint')}</p>
        <label>
          {t('admin.templatesPage.css')}
          <input
            type="file"
            accept=".css,text/css"
            onChange={(e) => void readUploadedFile(e).then((text) => text !== null && setNewTemplate({ ...newTemplate, css: text }))}
            required
          />
        </label>
        <button type="submit">{t('admin.templatesPage.create')}</button>
        {createError && <p role="alert">{createError}</p>}
      </form>
    </section>
  )
}
