import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { describeError } from './adminErrors'
import { COLOR_KEYS, useTheme, type TemplateColors, type TemplateSummary } from '../../theme/ThemeContext'
import { BUILT_IN_TEMPLATE_COLORS } from '../../theme/builtInColors'

interface FullTemplate extends TemplateSummary {
  colors: TemplateColors
}

interface BundledTemplate extends TemplateSummary {
  installed: boolean
}

const BUILT_IN_CODES = ['light', 'dark'] as const

/** The 8 `--color-*` keys get an `<input type="color">` each; `color-scheme` (not a color) gets its own light/dark <select> below. */
const COLOR_PICKER_KEYS = COLOR_KEYS.filter((key) => key !== 'color-scheme')

function emptyColors(): TemplateColors {
  return {
    'color-bg': '#ffffff',
    'color-surface': '#ffffff',
    'color-text': '#000000',
    'color-text-muted': '#666666',
    'color-border': '#cccccc',
    'color-accent': '#2f6fed',
    'color-danger': '#c62828',
    'color-danger-bg': '#fdecea',
    'color-scheme': 'light',
  }
}

/**
 * A top-level component, not nested inside TemplatesPage() — defining a
 * component function inside another component's render body would give it
 * a brand-new identity on every render, forcing React to unmount/remount
 * its <input type="color"> fields (and lose focus) on every keystroke
 * elsewhere on the page.
 */
function ColorFields({ colors, onChange }: { colors: TemplateColors; onChange: (colors: TemplateColors) => void }) {
  const { t } = useTranslation()

  return (
    <>
      {COLOR_PICKER_KEYS.map((key) => (
        <label key={key}>
          {t(`admin.templatesPage.colorFields.${key}`)}
          <input type="color" value={colors[key]} onChange={(e) => onChange({ ...colors, [key]: e.target.value })} />
        </label>
      ))}
      <label>
        {t('admin.templatesPage.colorFields.color-scheme')}
        <select value={colors['color-scheme']} onChange={(e) => onChange({ ...colors, 'color-scheme': e.target.value })}>
          <option value="light">{t('settings.template.light')}</option>
          <option value="dark">{t('settings.template.dark')}</option>
        </select>
      </label>
    </>
  )
}

/**
 * Admin CRUD for UI templates beyond the bundled light/dark
 * (briefing 10./11.4, GitHub issue #11). Deliberate structural mirror of
 * LanguagesPage.tsx (see that component's own docblock for the shared
 * reasoning: bundled-template install section, built-in light/dark listed
 * view/download-only, every successful create/edit/delete updates the live
 * ThemeContext registry so a template an admin just touched is immediately
 * selectable in this same tab). The one real difference: a template's
 * `colors` is a small, fixed 9-key set (Template::REQUIRED_COLOR_KEYS),
 * unlike a language pack's ~260-key `translations` — so this uses a real
 * <input type="color"> picker per key instead of a raw JSON <textarea>,
 * which briefing 11.4's "einfacher Texteditor" reasoning for language
 * packs doesn't apply to at all here.
 */
export function TemplatesPage() {
  const { t } = useTranslation()
  const { registerTemplate, unregisterTemplate } = useTheme()
  const [templates, setTemplates] = useState<TemplateSummary[]>([])
  const [loading, setLoading] = useState(true)

  const [bundled, setBundled] = useState<BundledTemplate[]>([])
  const [installingCode, setInstallingCode] = useState<string | null>(null)
  const [installError, setInstallError] = useState<string | null>(null)

  const [newCode, setNewCode] = useState('')
  const [newName, setNewName] = useState('')
  const [newColors, setNewColors] = useState<TemplateColors>(emptyColors)
  const [createError, setCreateError] = useState<string | null>(null)

  const [editingCode, setEditingCode] = useState<string | null>(null)
  const [editName, setEditName] = useState('')
  const [editColors, setEditColors] = useState<TemplateColors>(emptyColors)
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
   * A validation error on `colors` means a required key is missing, which
   * the color-picker form below can't actually produce (every field always
   * has a value), so it's not specially handled — describeError()'s
   * generic fallback covers it if it somehow still happens.
   */
  function describeTemplateError(err: unknown): string {
    if (isAxiosError(err)) {
      const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
      if (errors?.code) return t('admin.templatesPage.errors.codeInvalid')
    }

    return describeError(err, t)
  }

  async function createTemplate(e: React.FormEvent) {
    e.preventDefault()
    setCreateError(null)

    try {
      const { data } = await apiClient.post<FullTemplate>('/admin/templates', {
        code: newCode,
        name: newName,
        colors: newColors,
      })
      registerTemplate(data.code, data.name, data.colors)
      setNewCode('')
      setNewName('')
      setNewColors(emptyColors())
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
    setEditColors(data.colors)
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
        colors: editColors,
      })
      registerTemplate(data.code, data.name, data.colors)
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
      registerTemplate(data.code, data.name, data.colors)
      await load()
      await loadBundled()
    } catch (err) {
      setInstallError(describeTemplateError(err))
    } finally {
      setInstallingCode(null)
    }
  }

  /** Same reasoning as LanguagesPage.tsx's downloadBuiltIn() — light/dark are already fully present client-side, no server round trip needed. */
  function downloadBuiltIn(code: (typeof BUILT_IN_CODES)[number]) {
    const payload = { code, name: t(`settings.template.${code}`), colors: BUILT_IN_TEMPLATE_COLORS[code] }
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `medinv-template-${code}.json`
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
                    <textarea rows={10} readOnly value={JSON.stringify(BUILT_IN_TEMPLATE_COLORS[code], null, 2)} />
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
                    <ColorFields colors={editColors} onChange={setEditColors} />
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
          <input value={newCode} onChange={(e) => setNewCode(e.target.value)} placeholder="solarized" maxLength={20} required />
        </label>
        <label>
          {t('common.name')}
          <input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Solarized" required />
        </label>
        <ColorFields colors={newColors} onChange={setNewColors} />
        <button type="submit">{t('admin.templatesPage.create')}</button>
        {createError && <p role="alert">{createError}</p>}
      </form>
    </section>
  )
}
