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
 *
 * Also home to SORTABLE_COLUMNS — not itself about creating items, but the
 * same per-media-type mapping concept modelClassFor() below already is,
 * and needed by more than one controller (MediaItemController's own item
 * list, LibraryController::exportPdf(), GitHub issue #128), so it lives
 * here rather than duplicated or awkwardly owned by just one of them.
 */
class MediaItemService
{
    /**
     * Columns the item-list table (LibraryDetailPage.tsx, GitHub issue #77)
     * lets a user sort by, per media_type — always `title`/`ean` (every
     * media type has both) plus that type's own subtitle column (mirrors
     * the frontend's `subtitle()` helper). Whitelisted rather than passing
     * `sort_by` straight into orderBy() since it comes from an untrusted
     * query param and orderBy() doesn't parameterize column names.
     */
    public const SORTABLE_COLUMNS = [
        // location (GitHub issue #108) — every media type's own table has
        // this column (GitHub issue #96), so it's sortable everywhere too.
        'book' => ['title', 'authors', 'ean', 'location'],
        // release_date/runtime_seconds (GitHub issue #98) — sortable columns
        // for the two extra CD-only table columns; track count has no
        // dedicated `tracks` column to sort by (it's a JSON array's length),
        // so it stays unsortable.
        'cd' => ['title', 'artist', 'ean', 'release_date', 'runtime_seconds', 'location'],
        'dvd_bluray' => ['title', 'director', 'ean', 'location'],
    ];

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
     * GitHub issue #151: an item captured without a real, known EAN (manual
     * entry with the field left blank, or a metadata candidate found via
     * free-text search rather than an EAN/barcode lookup) still needs a
     * value for the `ean` column — it's part of this app's own per-library
     * uniqueness rule (briefing 5.1, enforced by create() above) and every
     * media table's `ean` column is `NOT NULL`. Rather than leaving it
     * genuinely empty, a `NoEAN-{13 random digits}` placeholder (19
     * characters — see the migration that widened the `ean` column to fit
     * it) makes the fact "this item has no real EAN" visible and greppable
     * in the data itself, instead of an empty string that looks like a
     * data-entry mistake.
     *
     * Collisions are checked the same way create() itself already scopes
     * EAN uniqueness — per library, not globally — regenerating and
     * re-checking until a free one is found. 13 random digits is a
     * 10^13-sized keyspace, so a real collision is astronomically unlikely
     * for any one library's realistic size, but explicitly checked and
     * retried anyway rather than just trusted, per the feature's own
     * request; only capped at a small number of attempts as a defensive
     * bound against a pathological/misbehaving random source, not because
     * a real collision is actually expected to occur.
     */
    public function generateNoEanPlaceholder(Library $library): string
    {
        $modelClass = $this->modelClassFor($library->media_type);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $this->randomNoEanCandidate();

            if (! $modelClass::query()->where('library_id', $library->id)->where('ean', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Never realistically reached (see this method's own docblock on
        // just how large the keyspace is) — a hard failure here is more
        // honest than silently returning a colliding value that create()
        // would just reject with a confusing DuplicateEanException anyway.
        throw new \RuntimeException('Could not generate a unique NoEAN placeholder after 20 attempts.');
    }

    /**
     * Split out of generateNoEanPlaceholder() above purely so a test can
     * override just this one seam (a partial mock on a real PHP CSPRNG call
     * can't otherwise be made to deterministically collide) to exercise the
     * retry loop itself — not because anything else calls this on its own.
     */
    protected function randomNoEanCandidate(): string
    {
        return 'NoEAN-'.str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT);
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
     *
     * Public (not just create()/updateFromMetadata()'s private helper)
     * because MediaItemController::update() — the manual-edit path, GitHub
     * issue #90 — needs the exact same derivation when a user hand-edits a
     * CD's track list: without this, a manually corrected `tracks` array
     * would leave a stale `runtime_seconds` in place, the same problem this
     * method already solves for capture/reimport.
     */
    public function withDerivedRuntime(array $attributes): array
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
