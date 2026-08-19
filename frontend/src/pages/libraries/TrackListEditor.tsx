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
          <input
            className="media-item-dialog__track-duration-input"
            type="number"
            step="any"
            aria-label={t('mediaItem.trackDurationSeconds')}
            placeholder={t('mediaItem.trackDurationSeconds')}
            value={track.duration_seconds ?? ''}
            onChange={(e) => updateTrack(index, { duration_seconds: e.target.value === '' ? null : Number(e.target.value) })}
          />
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
