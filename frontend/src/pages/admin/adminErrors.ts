import type { TFunction } from 'i18next'
import { isAxiosError } from 'axios'

/**
 * Turns an API error into a message worth showing the admin, instead of the
 * request just failing silently (a real bug: creating a user with a
 * policy-violating password 422ed with no feedback at all — the caller
 * simply never awaited/caught the rejection). Known `error_code`s get their
 * translated string; Laravel's default `{errors: {field: [messages]}}`
 * validation shape gets a translated hint for the password policy
 * specifically (the most common cause) or the raw field messages
 * otherwise; anything else falls back to the generic translation.
 *
 * Shared across every admin/*.tsx page (users, plugins, backups, mail,
 * settings) rather than duplicated per-page, since they all talk to the
 * same admin API and hit the same error shapes.
 */
export function describeError(err: unknown, t: TFunction): string {
  if (!isAxiosError(err)) return t('errors.generic')

  const data = err.response?.data as
    | {
        error_code?: string
        errors?: Record<string, string[]>
        libraries?: { id: number; name: string }[]
        context?: { index?: number; field?: string; library?: string }
      }
    | undefined
  if (data?.error_code === 'protected_account') return t('admin.errors.protected_account')
  if (data?.error_code === 'owns_libraries') {
    const names = (data.libraries ?? []).map((l) => l.name).join(', ')
    return t('admin.errors.ownsLibraries', { libraries: names })
  }
  // ExportImportController::import() rejecting a malformed/unrelated file
  // (InvalidImportFileException) — see that exception's docblock. `index` is
  // 0-based on the wire; +1 here so the admin sees a 1-based position
  // matching how they'd count entries by eye.
  if (data?.error_code === 'import_invalid_json') return t('admin.errors.import_invalid_json')
  if (data?.error_code === 'import_missing_libraries') return t('admin.errors.import_missing_libraries')
  if (data?.error_code === 'import_invalid_library') {
    return t('admin.errors.import_invalid_library', {
      number: (data.context?.index ?? 0) + 1,
      field: data.context?.field ?? '?',
    })
  }
  if (data?.error_code === 'import_invalid_item') {
    return t('admin.errors.import_invalid_item', {
      number: (data.context?.index ?? 0) + 1,
      library: data.context?.library ?? '?',
    })
  }
  if (data?.errors) {
    if (data.errors.password) return t('admin.errors.passwordPolicy')

    return Object.values(data.errors).flat().join(' ')
  }

  return t('errors.generic')
}

/**
 * Same as describeError(), but for a request made with `responseType:
 * 'blob'` (ExportImportPage.tsx's export download) — on a failed request,
 * axios still hands back whatever body the server sent as a Blob rather
 * than parsed JSON, since it doesn't know the response was actually an
 * error until after the blob type was already committed to. describeError()
 * expects `err.response.data` to already be the parsed error object, so an
 * error blob needs to be read back to text and JSON-parsed first.
 */
export async function describeBlobError(err: unknown, t: TFunction): Promise<string> {
  if (isAxiosError(err) && err.response?.data instanceof Blob) {
    try {
      const parsed: unknown = JSON.parse(await err.response.data.text())
      return describeError({ ...err, response: { ...err.response, data: parsed } }, t)
    } catch {
      return t('errors.generic')
    }
  }

  return describeError(err, t)
}
