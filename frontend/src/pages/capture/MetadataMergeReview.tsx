import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FIELD_SPECS, dateOnly, formatDuration, type FieldSpec, type MediaType, type Track } from '../libraries/mediaItemFields'

interface MergedFieldOption {
  value: string | number
  provider_keys: string[]
}

export interface MergedField {
  value: string | number | null
  agreed: boolean
  options: MergedFieldOption[]
}

/** A CD's `tracks` field (GitHub issue #48) is shaped like MergedField, but each option's `value` is a whole track list rather than a scalar — kept as its own type instead of widening MergedField's `value`/`options[].value`, which would force every ordinary scalar field to deal with an array case it can never actually have. */
export interface MergedTracksFieldOption {
  value: Track[]
  provider_keys: string[]
}

export interface MergedTracksField {
  value: Track[] | null
  agreed: boolean
  options: MergedTracksFieldOption[]
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
  /**
   * Distinguishes this review's radio button groups from any other
   * MetadataMergeReview rendered at the same time (CapturePage.PendingResult's
   * client-side id) — using `ean` for this instead used to be the bug: two
   * pending results for the same EAN (e.g. a hardware scanner double-firing)
   * produced two <input type="radio" name="{ean}-..."> groups with an
   * identical `name`, which the browser groups globally across the whole
   * page regardless of which review dialog they're in, so picking an option
   * in one silently cleared the "same" option in the other.
   */
  groupId: number
  ean: string
  mediaType: MediaType
  merged: MergedMetadata
  /**
   * The item's own already-stored values (GitHub issue #186) — passed only
   * when this review is re-querying metadata for an *already captured*
   * item (MediaItemDetailDialog's "Metadaten erneut abfragen", GitHub
   * issue #56), never for a brand-new capture (CapturePage and
   * CreateMediaItemDialog have no "current state" to preselect against, so
   * they simply omit this prop). When present, a disagreed field/tracks
   * option defaults to whichever one already matches the current item
   * instead of just the first, provider-ranked option — so confirming
   * without touching anything reproduces the existing record rather than
   * silently overwriting it with an unrelated provider guess.
   *
   * `coverUrl` (GitHub issue #187) is the item's current, already-rendered
   * cover image src (e.g. mediaItemFields.ts's coverSrc()), or null/absent
   * when the item has no cover right now — shown and preselected as its
   * own dedicated "Cover beibehalten" option (see selectedCoverUrl below),
   * distinct from "Kein Cover": none of the fetched candidate cover URLs
   * can ever equal it (they're always hosted by the metadata provider, not
   * this app), so it can't just be matched against `merged.covers` the way
   * an ordinary field value is matched against its options above.
   */
  current?: { values: Record<string, string>; tracks: Track[] | null; coverUrl?: string | null }
  /**
   * `providerKeys` (GitHub issue #74) is the union of every field/tracks/
   * cover option's `provider_keys` that actually ended up in `attributes`/
   * `coverUrl` — computed in confirm() below from the exact option the user
   * kept (or the sole agreed-upon one), not every provider that merely
   * proposed *something* for this lookup. The caller forwards it as
   * `metadata_providers` to POST .../metadata/import or .../metadata/refresh,
   * which join it into MediaBook/MediaCd/MediaDvdBluray::metadata_provider.
   *
   * `coverUrl` (GitHub issue #187) is now tri-state, not just `string |
   * null`: a candidate's URL when chosen, `null` for an explicit "Kein
   * Cover" (no cover at all — MetadataController::import()'s ordinary
   * behavior, and reimport()'s new `remove_cover` signal), or `undefined`
   * for "Cover beibehalten" (only reachable when `current.coverUrl` was
   * passed in — leave the item's existing cover untouched, the refresh
   * review's own default). CapturePage/CreateMediaItemDialog's own
   * onConfirm handlers never actually receive `undefined` in practice
   * (they never pass `current`, so selectedCoverUrl below can never become
   * that sentinel there) but still type against it for consistency.
   */
  onConfirm: (attributes: Record<string, unknown>, coverUrl: string | null | undefined, providerKeys: string[]) => void
  onReject: () => void
}

/**
 * GitHub issue #53: per-provider request outcome, alongside a scan/refresh
 * result — 'ok' still means "the request succeeded", not "it found a match
 * a user would pick"; a provider can be 'ok' with candidate_count 0
 * disagreements resolved elsewhere in `merged`. 'skipped' (GitHub issue
 * #159) is a fourth status alongside those three, specific to `stage:
 * 'title'`: this provider never got queried at all this time, because
 * round 1 (the EAN lookup) reported no usable title at all to search
 * with — see MetadataImportService::collectCandidatesByCode()'s own
 * docblock for why that's a deliberate choice, not a bug.
 */
export interface ProviderStatus {
  provider_key: string
  status: 'ok' | 'no_match' | 'failed' | 'skipped'
  candidate_count: number
  /**
   * GitHub issue #159: 'code' for an ordinary EAN-based lookup (every
   * status before this issue), 'title' for a provider that can never
   * support an EAN lookup at all (`supportsCodeLookup() === false`, GitHub
   * issue #158 — TMDB today) and was instead queried by up to the top 3
   * titles round 1 itself reported (MetadataImportService::
   * resolveCandidateTitles()'s own docblock — a single title when every
   * round-1 candidate that reported one agrees, up to three when they
   * don't, rather than giving up on any disagreement). ProviderStatusList
   * below surfaces this so a title-round contribution doesn't look
   * indistinguishable from an ordinary EAN match — title-based matching is
   * inherently less certain (no barcode to confirm it), worth knowing at
   * a glance.
   *
   * GitHub issue #192: 'fallback' for a NameOnlyFallbackProvider (today,
   * upcitemdb.com) — a generic, non-media-specific barcode database only
   * ever queried as a last resort, when every ordinary code-capable
   * provider found nothing at all in round 1. Surfaced with its own hint
   * for the same reason 'title' gets one: this contribution is
   * lower-confidence than an ordinary media-specific match and shouldn't
   * look identical to one.
   */
  stage: 'code' | 'title' | 'fallback'
}

/**
 * "cd.discogs" -> "Discogs", "book.open_library" -> "Open Library" — a small
 * generic formatter rather than a lookup table, since a provider's
 * human-readable name (MetadataPlugin.name) is only exposed via the
 * admin-only /admin/metadata/plugins endpoint and this page is usable by
 * any user with library write access, not just admins. Exported (GitHub
 * issue #74) for ReportsPage.tsx's capture-source report, which renders the
 * same provider_key values (MediaBook/MediaCd/MediaDvdBluray::
 * metadata_provider) outside this component entirely.
 */
export function formatProviderKey(key: string): string {
  const withoutMediaType = key.includes('.') ? key.slice(key.indexOf('.') + 1) : key
  return withoutMediaType
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

/**
 * GitHub issue #53: shows how each enabled provider's request actually
 * went, so a misconfigured API key or a blocked scraper (e.g. the Amazon
 * ones from #50) shows up distinctly from "no provider found a match" —
 * previously indistinguishable from the merged result alone, with the
 * failure reaching only the server log. Rendered both for a 'no_match'
 * overall result (where it's most useful) and alongside a 'candidates'
 * result's MetadataMergeReview.
 */
export function ProviderStatusList({ statuses }: { statuses: ProviderStatus[] }) {
  const { t } = useTranslation()

  if (statuses.length === 0) return null

  return (
    <ul className="provider-status-list">
      {statuses.map((s) => (
        <li key={s.provider_key} className="provider-status-list__item">
          <span>{formatProviderKey(s.provider_key)}:</span>
          <span className={`provider-status-list__status--${s.status}`}>
            {t(`capture.providerStatus.${s.status}`, { count: s.candidate_count })}
          </span>
          {/*
            GitHub issue #159: 'skipped' already says *why* nothing
            happened (no title to search with yet) — appending "found via
            title" there too would read as a contradiction rather than an
            explanation, so this is shown for every other title-stage
            status ('ok'/'no_match'/'failed') instead.
          */}
          {s.stage === 'title' && s.status !== 'skipped' && <span className="hint provider-status-list__via">{t('capture.foundViaTitle')}</span>}
          {/* GitHub issue #192: a NameOnlyFallbackProvider's contribution is always labeled, regardless of status — unlike 'title', there's no 'skipped' variant to avoid contradicting here. */}
          {s.stage === 'fallback' && <span className="hint provider-status-list__via">{t('capture.foundViaFallback')}</span>}
        </li>
      ))}
    </ul>
  )
}

/**
 * GitHub issue #186: does a merge option's value already match the item's
 * current, already-stored value for this field — used to preselect "what's
 * already there" instead of just the first provider-ranked option when
 * refreshing an existing item's metadata. Comparing by plain `===` (like
 * `confirm()` above does for a *selected* option) doesn't work here since
 * the two sides come from genuinely different representations: `current`
 * is always a form-input string (mediaItemFields.ts's `valuesFromItem()`),
 * while a number-type field's option value is a real number and a
 * date-type field's option value may carry more than just the date (e.g.
 * a full ISO timestamp) — each type gets its own tolerant comparison
 * instead.
 */
function optionMatchesCurrentValue(optionValue: string | number, currentValue: string, fieldType: FieldSpec['type']): boolean {
  if (currentValue === '') return false
  if (fieldType === 'date') {
    return dateOnly(String(optionValue)) === dateOnly(currentValue)
  }
  if (fieldType === 'number') {
    const optionNumber = typeof optionValue === 'number' ? optionValue : Number(optionValue)
    const currentNumber = Number(currentValue)
    return !Number.isNaN(optionNumber) && !Number.isNaN(currentNumber) && optionNumber === currentNumber
  }
  return String(optionValue).trim() === currentValue.trim()
}

/** Same idea as optionMatchesCurrentValue() above, but for a CD's `tracks` option (GitHub issue #186) — position/title/duration_seconds compared field-by-field rather than by reference, since the current item's tracks and a freshly merged option's tracks are always distinct array instances even when they describe the same track list. */
function tracksMatchCurrent(optionTracks: Track[], currentTracks: Track[]): boolean {
  if (optionTracks.length !== currentTracks.length) return false
  return optionTracks.every((track, index) => {
    const current = currentTracks[index]
    return (
      String(track.position ?? '') === String(current.position ?? '') &&
      (track.title ?? '') === (current.title ?? '') &&
      (track.duration_seconds ?? null) === (current.duration_seconds ?? null)
    )
  })
}

/** Total duration of a track list, formatted, only when every track's duration is known — mirrors the backend's TrackListRuntimeCalculator (a confidently-wrong partial sum is worse than no total at all), used here purely to help the user tell two same-length-looking track list options apart at a glance. */
function totalTracksDuration(tracks: Track[]): string | null {
  if (tracks.some((t) => typeof t.duration_seconds !== 'number')) return null
  const total = tracks.reduce((sum, t) => sum + (t.duration_seconds ?? 0), 0)
  return formatDuration(total)
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
 *
 * A CD's `tracks` (GitHub issue #48) gets its own picker below the
 * ordinary fields rather than going through the generic FIELD_SPECS loop:
 * it isn't a single scalar value a plain text/number input represents (see
 * mediaItemFields.ts), and — critically — `runtime_seconds` is never
 * chosen independently of it (MediaItemService::create() derives the
 * runtime from whichever `tracks` ends up submitted here), so `tracks`
 * itself is the only thing this component needs to let the user pick.
 */
export function MetadataMergeReview({ groupId, ean, mediaType, merged, current, onConfirm, onReject }: Props) {
  const { t } = useTranslation()
  const specs = FIELD_SPECS[mediaType]
  // `merged.fields.tracks` (GitHub issue #48) has a genuinely different
  // option-value shape (a whole track list, not a scalar) than every other
  // entry in `fields` — this cast is the one place that difference is
  // acknowledged; the generic per-field loop below never touches 'tracks'
  // at all (it isn't in FIELD_SPECS), so it never needs to know about it.
  const tracksField = merged.fields.tracks as unknown as MergedTracksField | undefined

  // Undecided fields default to whichever option already matches the
  // item's current value (GitHub issue #186, only known when `current` is
  // passed — i.e. refreshing an already-captured item), falling back to
  // the first (provider-ranked) option otherwise — same fallback as before
  // this fix, and the only behavior for a brand-new capture, which has no
  // "current" value to match at all. Either way, clicking "confirm" without
  // touching anything still produces a complete, reasonable record.
  //
  // GitHub issue #189: initialized for *every* field with a merged entry,
  // not just a disagreed one — an agreed, non-required field still needs a
  // selectedValues entry so its radiogroup (see the render loop below) can
  // mark the found value as checked, alongside its own "Nicht übernehmen"
  // option. `null` is the sentinel for that rejection option, distinct
  // from any real option's value the same way selectedCoverUrl already
  // uses `null` for "Kein Cover".
  //
  // GitHub issue #218: `undefined` is a third sentinel — "keep the item's
  // current value" — for the case none of this round's fetched options
  // happen to reproduce it (a genuine provider disagreement, or a
  // provider-side bug like #217's own "Blu-ray Disc" vs. "Blu-ray"). This
  // defaults to `undefined` specifically *because* no option matched;
  // when one already does, that option itself represents "current" and is
  // preselected exactly as before this issue — never a second, visually
  // redundant "keep current" radio alongside an identical-looking option.
  // Only reachable for a non-required field (required's own branch in
  // confirm() below and its render block never look at this sentinel),
  // same population `spec.required` already gates "Nicht übernehmen" on.
  const [selectedValues, setSelectedValues] = useState<Record<string, string | number | null | undefined>>(() => {
    const initial: Record<string, string | number | null | undefined> = {}
    for (const spec of specs) {
      const field = merged.fields[spec.key]
      if (!field || field.options.length === 0) continue
      const currentValue = current?.values[spec.key]
      const matching = currentValue !== undefined ? field.options.find((option) => optionMatchesCurrentValue(option.value, currentValue, spec.type)) : undefined
      if (matching) {
        initial[spec.key] = matching.value
      } else if (!spec.required && currentValue !== undefined && currentValue !== '') {
        initial[spec.key] = undefined
      } else {
        initial[spec.key] = field.options[0].value
      }
    }
    return initial
  })
  // GitHub issue #186/#187: refreshing an existing item that currently has
  // a cover defaults to `undefined` — the dedicated "Cover beibehalten"
  // option below, leaving it untouched — rather than the first candidate
  // image, since none of the fetched candidate URLs can ever equal the
  // item's own already-stored cover path; there's nothing to match
  // against, so "don't change it" is the option that actually corresponds
  // to the current state. Refreshing an item that currently has *no* cover
  // defaults to `null` ("Kein Cover") instead — equally a correct match
  // for "no cover" being the current state, and there's no cover to show a
  // "keep current" option for in the first place. A brand-new capture (no
  // `current`) keeps defaulting to the first candidate, unchanged.
  const [selectedCoverUrl, setSelectedCoverUrl] = useState<string | null | undefined>(() => {
    if (current) return current.coverUrl ? undefined : null
    return merged.covers[0]?.url ?? null
  })
  // GitHub issue #218: same third "keep current" sentinel as
  // selectedValues above, `undefined` here too — a current track list
  // that no fetched option reproduces defaults to being kept rather than
  // silently discarded in favor of the first fetched option.
  const [selectedTracks, setSelectedTracks] = useState<Track[] | null | undefined>(() => {
    if (!tracksField || tracksField.agreed || tracksField.options.length === 0) return null
    const matching = current?.tracks ? tracksField.options.find((option) => tracksMatchCurrent(option.value, current.tracks!)) : undefined
    if (matching) return matching.value
    if (current?.tracks && current.tracks.length > 0) return undefined
    return tracksField.options[0].value
  })
  // GitHub issue #99: a larger view of a candidate cover, opened by clicking
  // its thumbnail — same native-<dialog> pattern as MediaItemDetailDialog's
  // own fullscreen cover view (issue #45), reusing its .media-item-cover-
  // dialog* styling. Kept separate from `selectedCoverUrl` (which cover is
  // *chosen*) since viewing one enlarged shouldn't select it.
  const [fullscreenCoverUrl, setFullscreenCoverUrl] = useState<string | null>(null)
  const coverDialogRef = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    if (fullscreenCoverUrl) {
      coverDialogRef.current?.showModal()
    } else {
      coverDialogRef.current?.close()
    }
  }, [fullscreenCoverUrl])

  function confirm() {
    const attributes: Record<string, unknown> = { ean }
    // GitHub issue #74: which provider(s) actually contributed a field that
    // ended up in `attributes`/the chosen cover — an agreed field's sole
    // option lists every provider that agreed on it; a picked field's
    // matching option (found by value, since a scalar option's `value`
    // compares fine by `===`) lists just the one(s) behind that choice.
    const providerKeys = new Set<string>()

    for (const spec of specs) {
      const field = merged.fields[spec.key]
      if (!field) continue
      // Required fields (only `title`) have no "Nicht übernehmen" option
      // (see the render loop below) and no way to end up with a `null`
      // selectedValues entry — an agreed one is taken straight from
      // `field.value` exactly as before GitHub issue #189, unaffected by
      // that field's rejection option.
      if (spec.required && field.agreed) {
        attributes[spec.key] = field.value
        field.options[0]?.provider_keys.forEach((key) => providerKeys.add(key))
        continue
      }
      // GitHub issue #189: every other field is read straight from
      // selectedValues, which the radiogroup below keeps in sync with
      // either a real option's value or the `null` "Nicht übernehmen"
      // sentinel — an agreed field defaults there to its own (sole)
      // option, so confirming without touching anything still reproduces
      // the pre-#189 "just take the agreed value" behavior.
      const value = selectedValues[spec.key]
      // GitHub issue #218: `undefined` is "keep the current value" — left
      // out of `attributes` entirely (not sent as `null`) so
      // MediaItemService::updateFromMetadata()'s Eloquent update() leaves
      // this column untouched rather than erasing it, the same way an
      // omitted key already behaves for every other endpoint in this app.
      if (value === undefined) continue
      attributes[spec.key] = value
      if (value !== null) {
        field.options.find((option) => option.value === value)?.provider_keys.forEach((key) => providerKeys.add(key))
      }
    }
    if (tracksField) {
      if (tracksField.agreed) {
        attributes.tracks = tracksField.value
        tracksField.options[0]?.provider_keys.forEach((key) => providerKeys.add(key))
      } else if (selectedTracks) {
        // GitHub issue #218: `selectedTracks === undefined` ("keep the
        // current track list") already falls through this truthy check
        // exactly like `null` always did — `attributes.tracks` stays
        // unset, the same "omitted key, column left untouched" behavior
        // ordinary fields now get an explicit `continue` for above.
        attributes.tracks = selectedTracks
        // Reference equality, not structural: selectedTracks was set from this
        // exact same in-memory option.value (see the radio's onChange below), so
        // === finds it correctly even though Track[] can't be compared by value.
        tracksField.options.find((option) => option.value === selectedTracks)?.provider_keys.forEach((key) => providerKeys.add(key))
      }
    }
    if (selectedCoverUrl) {
      const cover = merged.covers.find((c) => c.url === selectedCoverUrl)
      if (cover) providerKeys.add(cover.provider_key)
    }

    onConfirm(attributes, selectedCoverUrl, Array.from(providerKeys))
  }

  return (
    <div className="metadata-merge">
      <p className="hint">{t('capture.chooseCandidate')}</p>

      {specs.map((spec) => {
        const field = merged.fields[spec.key]
        if (!field) return null

        // Required fields (only `title`) keep the exact pre-#189 display:
        // plain text once every provider agrees (there's nothing to
        // choose, and rejecting it outright isn't offered — the item
        // can't be created without one), a plain radiogroup with no
        // rejection option when they don't.
        if (spec.required) {
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
                        name={`${groupId}-${spec.key}`}
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
        }

        // GitHub issue #189: every other (optional) field always gets a
        // radiogroup — even one every provider agreed on, which used to be
        // shown as unselectable plain text — with a "Nicht übernehmen"
        // option appended after the found value(s), the same radio-button
        // pattern the cover picker's own "Kein Cover" already established,
        // rather than a separate checkbox next to a differently-styled
        // display.
        //
        // GitHub issue #218: the item's own current value for this field,
        // shown as its own selectable option — only when there is one and
        // no fetched option already reproduces it (optionMatchesCurrentValue,
        // same check the initial selectedValues computation above already
        // uses), so a genuine match never grows a second, visually
        // identical radio next to the option that already represents it.
        const currentValue = current?.values[spec.key]
        const showKeepCurrent =
          currentValue !== undefined && currentValue !== '' && !field.options.some((option) => optionMatchesCurrentValue(option.value, currentValue, spec.type))

        return (
          <div className="metadata-merge__field" key={spec.key}>
            <span className="metadata-merge__field-label">{t(`mediaItem.fields.${spec.key}`)}</span>
            <div className="metadata-merge__options" role="radiogroup" aria-label={t(`mediaItem.fields.${spec.key}`)}>
              {showKeepCurrent && (
                <label className="metadata-merge__option">
                  <input
                    type="radio"
                    name={`${groupId}-${spec.key}`}
                    checked={selectedValues[spec.key] === undefined}
                    onChange={() => setSelectedValues((prev) => ({ ...prev, [spec.key]: undefined }))}
                  />
                  <span>{currentValue}</span>
                  <span className="metadata-merge__option-source">{t('capture.mergeKeepValue')}</span>
                </label>
              )}
              {field.options.map((option) => (
                <label key={String(option.value)} className="metadata-merge__option">
                  <input
                    type="radio"
                    name={`${groupId}-${spec.key}`}
                    checked={selectedValues[spec.key] === option.value}
                    onChange={() => setSelectedValues((prev) => ({ ...prev, [spec.key]: option.value }))}
                  />
                  <span>{String(option.value)}</span>
                  <span className="metadata-merge__option-source">{option.provider_keys.map(formatProviderKey).join(', ')}</span>
                </label>
              ))}
              <label className="metadata-merge__option">
                <input
                  type="radio"
                  name={`${groupId}-${spec.key}`}
                  checked={selectedValues[spec.key] === null}
                  onChange={() => setSelectedValues((prev) => ({ ...prev, [spec.key]: null }))}
                />
                <span>{t('capture.mergeRejectField')}</span>
              </label>
            </div>
          </div>
        )
      })}

      {/*
        GitHub issue #97: for a CD, always show this row — present or not —
        rather than only rendering it when at least one provider actually
        reported a `tracks` field. Silently omitting the whole section
        whenever tracksField was undefined used to leave no way to tell
        "this candidate genuinely has no track list, confirming creates an
        item without one" apart from "the section just hasn't loaded/
        rendered for some other reason" — the same ambiguity every other
        merge field already has (see the specs.map() loop above, which
        equally skips a field no provider reported), but tracks is the one
        CD collectors most need a definite answer on before confirming.
      */}
      {mediaType === 'cd' && (
        <div className="metadata-merge__field">
          <span className="metadata-merge__field-label">{t('mediaItem.tracklist')}</span>
          {!tracksField ? (
            <span className="hint">{t('capture.mergeNoTracks')}</span>
          ) : tracksField.agreed ? (
            <span className="metadata-merge__agreed-value">
              {t('capture.mergeTracksCount', { count: tracksField.value?.length ?? 0 })}
            </span>
          ) : (
            <div className="metadata-merge__options" role="radiogroup" aria-label={t('mediaItem.tracklist')}>
              {/*
                GitHub issue #218: the item's own current track list, shown
                as its own selectable option — same "only when nothing
                fetched already reproduces it" gating as an ordinary
                field's "keep current" radio above.
              */}
              {current?.tracks && current.tracks.length > 0 && !tracksField.options.some((option) => tracksMatchCurrent(option.value, current.tracks!)) && (
                <label className="metadata-merge__option">
                  <input
                    type="radio"
                    name={`${groupId}-tracks`}
                    checked={selectedTracks === undefined}
                    onChange={() => setSelectedTracks(undefined)}
                  />
                  <span>{t('capture.mergeTracksCount', { count: current.tracks.length })}</span>
                  <span className="metadata-merge__option-source">{t('capture.mergeKeepValue')}</span>
                </label>
              )}
              {tracksField.options.map((option, index) => {
                const duration = totalTracksDuration(option.value)
                return (
                  <label key={index} className="metadata-merge__option">
                    <input
                      type="radio"
                      name={`${groupId}-tracks`}
                      checked={selectedTracks === option.value}
                      onChange={() => setSelectedTracks(option.value)}
                    />
                    <span>
                      {t('capture.mergeTracksCount', { count: option.value.length })}
                      {duration ? ` (${duration})` : ''}
                    </span>
                    <span className="metadata-merge__option-source">{option.provider_keys.map(formatProviderKey).join(', ')}</span>
                  </label>
                )
              })}
            </div>
          )}
        </div>
      )}

      {/*
        GitHub issue #187: also shown when there are no fetched candidate
        covers at all, as long as the item currently has one to keep/remove
        — otherwise a refresh that found no new cover candidates would
        offer no way to explicitly remove the existing cover here (only via
        the dialog's separate, always-available "remove cover" action).
      */}
      {(merged.covers.length > 0 || current?.coverUrl) && (
        <div className="metadata-merge__field">
          <span className="metadata-merge__field-label">{t('capture.mergeCover')}</span>
          <div className="metadata-merge__covers" role="radiogroup" aria-label={t('capture.mergeCover')}>
            {/* GitHub issue #187: the item's own current cover, shown and preselected the same way a candidate is — distinct from, and listed before, "Kein Cover" below. */}
            {current?.coverUrl && (
              <label className="metadata-merge__cover-option">
                <input
                  type="radio"
                  name={`${groupId}-cover`}
                  checked={selectedCoverUrl === undefined}
                  onChange={() => setSelectedCoverUrl(undefined)}
                />
                <button
                  type="button"
                  className="metadata-merge__cover-thumb-button"
                  onClick={(e) => {
                    e.preventDefault()
                    e.stopPropagation()
                    setFullscreenCoverUrl(current.coverUrl ?? null)
                  }}
                  aria-label={t('mediaItem.viewCoverFullscreen')}
                >
                  <img src={current.coverUrl} alt={t('capture.mergeKeepCover')} className="metadata-merge__cover-thumb" />
                </button>
                <span className="metadata-merge__option-source">{t('capture.mergeKeepCover')}</span>
              </label>
            )}
            {merged.covers.map((cover) => (
              <label key={cover.url} className="metadata-merge__cover-option">
                <input
                  type="radio"
                  name={`${groupId}-cover`}
                  checked={selectedCoverUrl === cover.url}
                  onChange={() => setSelectedCoverUrl(cover.url)}
                />
                {/* GitHub issue #99: enlarges on click; e.preventDefault()+stopPropagation() keep this from also toggling the radio via the enclosing <label>'s default click-forwarding. */}
                <button
                  type="button"
                  className="metadata-merge__cover-thumb-button"
                  onClick={(e) => {
                    e.preventDefault()
                    e.stopPropagation()
                    setFullscreenCoverUrl(cover.url)
                  }}
                  aria-label={t('mediaItem.viewCoverFullscreen')}
                >
                  <img src={cover.url} alt={formatProviderKey(cover.provider_key)} className="metadata-merge__cover-thumb" />
                </button>
                <span className="metadata-merge__option-source">{formatProviderKey(cover.provider_key)}</span>
              </label>
            ))}
            <label className="metadata-merge__cover-option">
              <input type="radio" name={`${groupId}-cover`} checked={selectedCoverUrl === null} onChange={() => setSelectedCoverUrl(null)} />
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

      {/* GitHub issue #99: same "any click closes it" behavior as MediaItemDetailDialog's fullscreen cover dialog (issue #45) — see that dialog's docblock for why. */}
      {fullscreenCoverUrl && (
        <dialog ref={coverDialogRef} className="media-item-cover-dialog" onClose={() => setFullscreenCoverUrl(null)} onClick={() => setFullscreenCoverUrl(null)}>
          <img className="media-item-cover-dialog__image" src={fullscreenCoverUrl} alt="" />
        </dialog>
      )}
    </div>
  )
}
