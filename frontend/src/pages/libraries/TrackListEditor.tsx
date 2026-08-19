import { useTranslation } from 'react-i18next'
import type { Track } from './mediaItemFields'

interface Props {
  tracks: Track[]
  onChange: (tracks: Track[]) => void
}

/**
 * A CD's track list editor — rows with position/title/duration,
 * add/remove/reorder (GitHub issue #90) — shown wherever `library.media_type
 * === 'cd'`, alongside but not part of the generic FIELD_SPECS-driven form:
 * a track list isn't a single scalar value a plain text/number input can
 * represent (see mediaItemFields.ts's own comment on why `tracks` is
 * deliberately excluded from FIELD_SPECS).
 *
 * Extracted out of MediaItemDetailDialog.tsx (GitHub issue #92) once
 * CreateMediaItemDialog.tsx needed the exact same editor for manual entry —
 * previously the only way to give a CD a track list was to save it first
 * (with no tracks at all) and then reopen the edit dialog, an unnecessary
 * detour especially right after a capture-flow `no_match` dead end (briefing
 * 7.1/7.2), which is the single most common reason to type a track list by
 * hand in the first place. Both dialogs own their own `tracks` state (a
 * plain `Track[]`, no create-vs-edit distinction needed here) and decide for
 * themselves how to fold it into their save/submit payload — this component
 * only renders the rows and reports edits back via onChange.
 */
export function TrackListEditor({ tracks, onChange }: Props) {
  const { t } = useTranslation()

  function addTrack() {
    onChange([...tracks, { position: String(tracks.length + 1), title: '', duration_seconds: null }])
  }

  function removeTrack(index: number) {
    onChange(tracks.filter((_, i) => i !== index))
  }

  function updateTrack(index: number, patch: Partial<Track>) {
    onChange(tracks.map((track, i) => (i === index ? { ...track, ...patch } : track)))
  }

  function moveTrack(index: number, direction: -1 | 1) {
    const target = index + direction
    if (target < 0 || target >= tracks.length) return
    const next = [...tracks]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange(next)
  }

  /**
   * GitHub issue #94: a track's duration is entered as minutes+seconds
   * (matching how it's actually known — a CD sleeve or player shows M:SS,
   * never raw seconds — and how formatDuration() already displays it
   * read-only elsewhere), not as a single raw-seconds field a person has to
   * convert by hand every time. `Track.duration_seconds` itself stays a
   * plain total-seconds number regardless (the backend's data model,
   * TrackListRuntimeCalculator/MediaCd.tracks, is unaffected) — only
   * derived to/from two displayed fields here. Blank+blank means "unknown"
   * (null); either field alone treats the other as 0, so filling in just
   * minutes or just seconds still produces a sensible total rather than
   * silently doing nothing. An out-of-range seconds value (e.g. typing 95)
   * isn't rejected — it's simply re-normalized into the total and
   * re-displayed correctly (95s -> 1:35) on the next render, the same way a
   * plain seconds field would have silently accepted an equally "wrong"
   * raw number before.
   *
   * A combined total of exactly 0 also collapses to null (never stored as
   * a literal 0:00): once either field has ever held a real value, the two
   * fields alone can no longer tell "minutes deliberately left blank"
   * apart from "minutes is 0" — both read back as "0" once folded into a
   * single total — so without this, clearing a duration back down to
   * "unknown" would get stuck at 0:00 forever instead of actually reaching
   * null again. A genuine zero-second track isn't a realistic scenario for
   * a CD tracklist, so this trade-off costs nothing in practice.
   */
  function updateDurationPart(index: number, part: 'minutes' | 'seconds', rawValue: string) {
    const totalSeconds = tracks[index].duration_seconds
    const currentMinutes = totalSeconds != null ? Math.floor(totalSeconds / 60) : null
    const currentSeconds = totalSeconds != null ? totalSeconds % 60 : null

    const newMinutes = part === 'minutes' ? (rawValue === '' ? null : Number(rawValue)) : currentMinutes
    const newSeconds = part === 'seconds' ? (rawValue === '' ? null : Number(rawValue)) : currentSeconds

    const combined = (newMinutes ?? 0) * 60 + (newSeconds ?? 0)
    const duration_seconds = (newMinutes === null && newSeconds === null) || combined === 0 ? null : combined
    updateTrack(index, { duration_seconds })
  }

  return (
    <div className="media-item-dialog__track-editor">
      <h4>{t('mediaItem.tracklist')}</h4>
      {tracks.map((track, index) => (
        <div key={index} className="media-item-dialog__track-editor-row">
          <input
            className="media-item-dialog__track-position-input"
            type="text"
            aria-label={t('mediaItem.trackPosition')}
            placeholder={t('mediaItem.trackPosition')}
            value={track.position ?? ''}
            onChange={(e) => updateTrack(index, { position: e.target.value })}
          />
          <input
            className="media-item-dialog__track-title-input"
            type="text"
            aria-label={t('mediaItem.trackTitle')}
            placeholder={t('mediaItem.trackTitle')}
            value={track.title ?? ''}
            onChange={(e) => updateTrack(index, { title: e.target.value })}
          />
          <span className="media-item-dialog__track-duration-group">
            <input
              className="media-item-dialog__track-duration-input"
              type="number"
              min={0}
              step={1}
              aria-label={t('mediaItem.trackDurationMinutes')}
              placeholder={t('mediaItem.trackDurationMinutes')}
              value={track.duration_seconds != null ? Math.floor(track.duration_seconds / 60) : ''}
              onChange={(e) => updateDurationPart(index, 'minutes', e.target.value)}
            />
            <span aria-hidden="true">:</span>
            <input
              className="media-item-dialog__track-duration-input"
              type="number"
              min={0}
              max={59}
              step={1}
              aria-label={t('mediaItem.trackDurationSeconds')}
              placeholder={t('mediaItem.trackDurationSeconds')}
              value={track.duration_seconds != null ? track.duration_seconds % 60 : ''}
              onChange={(e) => updateDurationPart(index, 'seconds', e.target.value)}
            />
          </span>
          <button type="button" onClick={() => moveTrack(index, -1)} disabled={index === 0} aria-label={t('mediaItem.moveTrackUp')}>
            ↑
          </button>
          <button type="button" onClick={() => moveTrack(index, 1)} disabled={index === tracks.length - 1} aria-label={t('mediaItem.moveTrackDown')}>
            ↓
          </button>
          <button type="button" onClick={() => removeTrack(index)} aria-label={t('mediaItem.removeTrack')}>
            ×
          </button>
        </div>
      ))}
      <button type="button" onClick={addTrack}>
        {t('mediaItem.addTrack')}
      </button>
    </div>
  )
}
