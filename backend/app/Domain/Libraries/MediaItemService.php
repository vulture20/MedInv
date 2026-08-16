<?php

namespace App\Domain\Libraries;

use App\Domain\Metadata\TrackListRuntimeCalculator;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use Illuminate\Database\Eloquent\Model;

/**
 * Type-agnostic create path for media items, used by both the single-entry
 * form and bulk import (briefing 7.1/7.2). Enforces the per-library EAN
 * uniqueness rule from 5.1 for every entry point so the rule can't be
 * bypassed by adding a new capture method later.
 */
class MediaItemService
{
    /** @throws DuplicateEanException */
    public function create(Library $library, array $attributes): Model
    {
        $modelClass = $this->modelClassFor($library->media_type);
        $attributes = $this->withDerivedRuntime($attributes);

        if ($modelClass::query()->where('library_id', $library->id)->where('ean', $attributes['ean'])->exists()) {
            throw new DuplicateEanException($attributes['ean']);
        }

        return $modelClass::query()->create([...$attributes, 'library_id' => $library->id]);
    }

    /**
     * Applies a re-run metadata lookup's user-picked fields onto an
     * *existing* item (GitHub issue #56) — the update-path counterpart to
     * create(). EAN is deliberately dropped rather than validated for a
     * duplicate: MetadataMergeReview.tsx always includes `ean` in its
     * assembled `attributes` (it's shared with the create-path confirm
     * flow, see MetadataController::import()), but this item's EAN cannot
     * legitimately change via a metadata refresh — same restriction
     * MediaItemController::update() already applies to a manual edit.
     * Shares withDerivedRuntime() with create() so a CD reimport that picks
     * a new `tracks` selection gets its `runtime_seconds` re-derived from
     * that selection the same way the original capture did, rather than
     * leaving a stale value from the old tracklist in place.
     */
    public function updateFromMetadata(Model $item, array $attributes): void
    {
        unset($attributes['ean']);
        $item->update($this->withDerivedRuntime($attributes));
    }

    /**
     * Derives a CD's `runtime_seconds`/`runtime_computed` from its `tracks`
     * (GitHub issue #48) — the single, central point every creation path
     * (manual entry, bulk capture, metadata import, backup/export restore)
     * funnels through, so this needs no per-caller wiring. A no-op for
     * book/dvd_bluray items and for any CD whose `attributes` doesn't
     * contain a `tracks` key at all — driven entirely by the shape of
     * `$attributes` itself, not a `$library->media_type === 'cd'` check,
     * the same "resolve via data, not an ad hoc type branch" spirit
     * CLAUDE.md documents for modelClassFor() callers elsewhere.
     *
     * Deliberately never overwrites an already-present, non-null
     * `runtime_seconds` — a provider that reports a genuine direct total
     * runtime of its own (none of the two CD providers implemented so far
     * do; see DiscogsProvider/MusicBrainzProvider's matching comments)
     * should have that value win over a derived one. And deriving it here,
     * once, from whichever `tracks` value is *actually* in `$attributes* by
     * the time this runs — after any merge-review picking already
     * happened client-side — is what guarantees the two numbers can never
     * end up mismatched (e.g. one provider's tracks paired with a
     * different provider's runtime), rather than each being independently
     * merge-picked upstream.
     */
    private function withDerivedRuntime(array $attributes): array
    {
        $tracks = $attributes['tracks'] ?? null;

        if (! is_array($tracks) || $tracks === []) {
            return $attributes;
        }

        if (array_key_exists('runtime_seconds', $attributes) && $attributes['runtime_seconds'] !== null) {
            return $attributes;
        }

        $runtimeSeconds = TrackListRuntimeCalculator::totalSeconds($tracks);

        if ($runtimeSeconds === null) {
            return $attributes;
        }

        return [...$attributes, 'runtime_seconds' => $runtimeSeconds, 'runtime_computed' => true];
    }

    /** @return class-string<Model> */
    public function modelClassFor(string $mediaType): string
    {
        return match ($mediaType) {
            'book' => MediaBook::class,
            'cd' => MediaCd::class,
            'dvd_bluray' => MediaDvdBluray::class,
        };
    }

    /**
     * Re-parents a media item into a different library (media item detail
     * dialog's "move" action). The two libraries' media_type must already
     * match by the time this is called — the record's own class (MediaBook/
     * MediaCd/MediaDvdBluray, per briefing 6.'s fixed-per-type table split)
     * only ever fits one specific media_type's table, so moving it into a
     * library of a different media_type isn't just disallowed by policy,
     * it's structurally meaningless; the caller (MediaItemController::move())
     * is responsible for that check plus the write-access checks on both
     * libraries. The destination's own per-library duplicate-EAN rule (5.1)
     * still applies here exactly as it does on create() — moving a book into
     * a library that already holds a copy with the same EAN is rejected the
     * same way a fresh capture of it would be.
     *
     * @throws DuplicateEanException
     */
    public function move(Model $item, Library $destination): void
    {
        $modelClass = $this->modelClassFor($destination->media_type);

        $duplicateExists = $modelClass::query()
            ->where('library_id', $destination->id)
            ->where('ean', $item->ean)
            ->where('id', '!=', $item->id)
            ->exists();

        if ($duplicateExists) {
            throw new DuplicateEanException($item->ean);
        }

        $item->update(['library_id' => $destination->id]);
    }
}
