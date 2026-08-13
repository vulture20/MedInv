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
    | { error_code?: string; errors?: Record<string, string[]>; libraries?: { id: number; name: string }[] }
    | undefined
  if (data?.error_code === 'protected_account') return t('admin.errors.protected_account')
  if (data?.error_code === 'owns_libraries') {
    const names = (data.libraries ?? []).map((l) => l.name).join(', ')
    return t('admin.errors.ownsLibraries', { libraries: names })
  }
  if (data?.errors) {
    if (data.errors.password) return t('admin.errors.passwordPolicy')

    return Object.values(data.errors).flat().join(' ')
  }

  return t('errors.generic')
}
