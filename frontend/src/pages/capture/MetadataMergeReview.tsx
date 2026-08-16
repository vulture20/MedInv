import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FIELD_SPECS, type MediaType } from '../libraries/mediaItemFields'

interface MergedFieldOption {
  value: string | number
  provider_keys: string[]
}

export interface MergedField {
  value: string | number | null
  agreed: boolean
  options: MergedFieldOption[]
}

export interface MergedCover {
  url: string
  provider_key: string
}

export interface MergedMetadata {
  fields: Record<string, MergedField>
  covers: MergedCover[]
}

interface Props {
  ean: string
  mediaType: MediaType
  merged: MergedMetadata
  onConfirm: (attributes: Record<string, unknown>, coverUrl: string | null) => void
  onReject: () => void
}

/** "cd.discogs" -> "Discogs", "book.open_library" -> "Open Library" — a small generic formatter rather than a lookup table, since a provider's human-readable name (MetadataPlugin.name) is only exposed via the admin-only /admin/metadata/plugins endpoint and this page is usable by any user with library write access, not just admins. */
function formatProviderKey(key: string): string {
  const withoutMediaType = key.includes('.') ? key.slice(key.indexOf('.') + 1) : key
  return withoutMediaType
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

/**
 * Field-by-field review of a merged metadata lookup (see MetadataMerger's
 * docblock — this refines briefing 8.3 steps 3-5 per explicit user
 * instruction): a field every provider that reported it agrees on is
 * filled in automatically, while a field providers disagree on — cover
 * images included — is offered as individually selectable options,
 * attributed to whichever source(s) reported each one. onConfirm() hands
 * the assembled result to the same POST .../metadata/import call the
 * previous whole-candidate picker already used, so nothing downstream of
 * this component changes.
 */
export function MetadataMergeReview({ ean, mediaType, merged, onConfirm, onReject }: Props) {
  const { t } = useTranslation()
  const specs = FIELD_SPECS[mediaType]

  // Undecided fields default to their first (provider-ranked) option, so
  // clicking "confirm" without touching anything still produces a complete,
  // reasonable record rather than forcing the user to resolve every
  // disagreement by hand.
  const [selectedValues, setSelectedValues] = useState<Record<string, string | number>>(() => {
    const initial: Record<string, string | number> = {}
    for (const spec of specs) {
      const field = merged.fields[spec.key]
      if (field && !field.agreed && field.options.length > 0) {
        initial[spec.key] = field.options[0].value
      }
    }
    return initial
  })
  const [selectedCoverUrl, setSelectedCoverUrl] = useState<string | null>(merged.covers[0]?.url ?? null)

  function confirm() {
    const attributes: Record<string, unknown> = { ean }
    for (const spec of specs) {
      const field = merged.fields[spec.key]
      if (!field) continue
      attributes[spec.key] = field.agreed ? field.value : (selectedValues[spec.key] ?? null)
    }
    onConfirm(attributes, selectedCoverUrl)
  }

  return (
    <div className="metadata-merge">
      <p className="hint">{t('capture.chooseCandidate')}</p>

      {specs.map((spec) => {
        const field = merged.fields[spec.key]
        if (!field) return null

        return (
          <div className="metadata-merge__field" key={spec.key}>
            <span className="metadata-merge__field-label">{t(`mediaItem.fields.${spec.key}`)}</span>
            {field.agreed ? (
              <span className="metadata-merge__agreed-value">{String(field.value)}</span>
            ) : (
              <div className="metadata-merge__options" role="radiogroup" aria-label={t(`mediaItem.fields.${spec.key}`)}>
                {field.options.map((option) => (
                  <label key={String(option.value)} className="metadata-merge__option">
                    <input
                      type="radio"
                      name={`${ean}-${spec.key}`}
                      checked={selectedValues[spec.key] === option.value}
                      onChange={() => setSelectedValues((prev) => ({ ...prev, [spec.key]: option.value }))}
                    />
                    <span>{String(option.value)}</span>
                    <span className="metadata-merge__option-source">{option.provider_keys.map(formatProviderKey).join(', ')}</span>
                  </label>
                ))}
              </div>
            )}
          </div>
        )
      })}

      {merged.covers.length > 0 && (
        <div className="metadata-merge__field">
          <span className="metadata-merge__field-label">{t('capture.mergeCover')}</span>
          <div className="metadata-merge__covers" role="radiogroup" aria-label={t('capture.mergeCover')}>
            {merged.covers.map((cover) => (
              <label key={cover.url} className="metadata-merge__cover-option">
                <input
                  type="radio"
                  name={`${ean}-cover`}
                  checked={selectedCoverUrl === cover.url}
                  onChange={() => setSelectedCoverUrl(cover.url)}
                />
                <img src={cover.url} alt={formatProviderKey(cover.provider_key)} className="metadata-merge__cover-thumb" />
                <span className="metadata-merge__option-source">{formatProviderKey(cover.provider_key)}</span>
              </label>
            ))}
            <label className="metadata-merge__cover-option">
              <input type="radio" name={`${ean}-cover`} checked={selectedCoverUrl === null} onChange={() => setSelectedCoverUrl(null)} />
              <span>{t('capture.mergeNoCover')}</span>
            </label>
          </div>
        </div>
      )}

      <div className="metadata-merge__actions">
        <button type="button" onClick={confirm}>
          {t('capture.mergeConfirm')}
        </button>
        <button type="button" onClick={onReject}>
          {t('capture.rejectAll')}
        </button>
      </div>
    </div>
  )
}
