<?php

namespace App\Http\Controllers\Api;

use App\Domain\Libraries\CurrencyConversionService;
use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\LibraryAccessService;
use App\Domain\Libraries\MediaItemService;
use App\Domain\Metadata\CoverDownloadService;
use App\Domain\Metadata\MetadataImportService;
use App\Domain\Metadata\MetadataProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\MetadataPlugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Metadata search/import (briefing 8.). Chosen-candidate confirmation
 * (`import()`) is a thin wrapper around MediaItemService — the candidate's
 * `attributes` are merged straight into the create call, then the resulting
 * record can still be edited before saving on the frontend (8.3 step 6).
 */
class MetadataController extends Controller
{
    public function __construct(
        private readonly LibraryAccessService $access,
        private readonly MetadataImportService $importService,
        private readonly MediaItemService $mediaItemService,
        private readonly CoverDownloadService $coverDownloadService,
        private readonly MetadataProviderRegistry $registry,
        private readonly CurrencyConversionService $currencyConversion,
    ) {}

    /**
     * All admin-visible plugins, or only those enabled for a media type
     * (briefing 15.). Each row carries a `config_fields` attribute (GitHub
     * issue #29), a `version` attribute (GitHub issue #44), a
     * `source_type` attribute (GitHub issue #55, 'api'|'scraping'|'llm' —
     * the third value added by GitHub issue #59's Claude providers), and a
     * `supports_code_lookup` attribute (GitHub issue #158) — all four
     * declared by the matching provider class, not stored in the
     * database — so PluginsPage.tsx can render a settings form and show a
     * version/source type/EAN-support per plugin without any of them
     * needing their own migration/sync step.
     *
     * `orderBy('id')` as a tie-breaker after `priority` matters more than it
     * looks: MetadataProviderRegistry::syncToDatabase() never sets an
     * explicit priority on first insert, so every provider starts at the
     * column default (0) — a full tie across an entire media type until an
     * admin actually reorders something. Without a deterministic secondary
     * key, that tie's row order would be whatever the database happens to
     * return it in, which could visibly reshuffle between requests and made
     * PluginsPage.tsx's drag-to-reorder list (which needs *some* stable
     * initial order to render at all) unreliable before an admin ever
     * touched it.
     */
    public function plugins(Request $request)
    {
        $query = MetadataPlugin::query();

        if ($mediaType = $request->query('media_type')) {
            $query->where('media_type', $mediaType);
        }

        $configFields = $this->registry->configFieldsByProviderKey();
        $versions = $this->registry->versionsByProviderKey();
        $sourceTypes = $this->registry->sourceTypesByProviderKey();
        $eanSupport = $this->registry->eanSupportByProviderKey();

        return $query->orderBy('priority')->orderBy('id')->get()->map(function (MetadataPlugin $plugin) use ($configFields, $versions, $sourceTypes, $eanSupport) {
            $plugin->setAttribute('config_fields', $configFields->get($plugin->provider_key, []));
            $plugin->setAttribute('version', $versions->get($plugin->provider_key));
            // GitHub issue #55.
            $plugin->setAttribute('source_type', $sourceTypes->get($plugin->provider_key));
            // GitHub issue #158.
            $plugin->setAttribute('supports_code_lookup', $eanSupport->get($plugin->provider_key));

            return $plugin;
        });
    }

    public function search(Request $request, Library $library)
    {
        abort_unless($this->access->canWriteItems($request->user(), $library), 403);

        $data = $request->validate(['query' => ['required', 'string']]);

        return response()->json($this->importService->search($library, $data['query']));
    }

    /**
     * Confirms one previously returned candidate and creates the media
     * record from it (briefing 8.3, steps 4-6). The user may also reject
     * all candidates client-side and call MediaItemController::store()
     * directly instead — this endpoint is purely opt-in.
     */
    public function import(Request $request, Library $library)
    {
        abort_unless($this->access->canWriteItems($request->user(), $library), 403);

        $data = $request->validate([
            'attributes' => ['required', 'array'],
            'cover_url' => ['nullable', 'string'],
            // GitHub issue #74: which provider(s) actually contributed the
            // confirmed fields (MetadataMergeReview.tsx's per-field
            // MergedField.options[].provider_keys, unioned client-side across
            // whichever fields/tracks/cover the user kept) — not part of
            // `attributes` itself (see stripInternallyManagedFields()), since
            // it isn't item data, it's metadata about this request. Not
            // validated against a known-provider whitelist, same stance
            // `currency` already takes (see MediaBook::casts() docblock) —
            // this only records what the frontend reports, it doesn't drive
            // any logic that would need it to be authoritative.
            'metadata_providers' => ['nullable', 'array'],
            'metadata_providers.*' => ['string'],
        ]);

        // GitHub issue #151: a candidate found via free-text metadata search
        // (as opposed to an EAN/barcode lookup) genuinely has no `ean` at
        // all — MetadataProviderInterface::search() implementations always
        // report `null` for it, since there was no scanned code to attach.
        // Generating a placeholder here, rather than rejecting the request
        // the way this used to (see git history for the previous
        // `attributes.ean` presence check this replaced), is what actually
        // lets that flow reach MediaItemService::create() at all — every
        // media table's `ean` column is NOT NULL.
        if (empty($data['attributes']['ean']) || ! is_string($data['attributes']['ean'])) {
            $data['attributes']['ean'] = $this->mediaItemService->generateNoEanPlaceholder($library);
        }

        $data['attributes'] = $this->stripInternallyManagedFields($data['attributes']);

        // GitHub issue #64 — see CurrencyConversionService's docblock and
        // MediaItemController::store()'s matching call.
        $data['attributes'] = $this->currencyConversion->convertToDefaultCurrency($data['attributes']);

        // GitHub issue #74: this endpoint is exclusively reached from
        // MetadataMergeReview's confirm (CapturePage.tsx) — the automated
        // scan/text-file capture pipeline, as opposed to
        // MediaItemController::store()'s standalone manual-entry form — so
        // capture_method is hardcoded here, not client-supplied.
        $data['attributes']['capture_method'] = 'scan';
        $data['attributes']['metadata_provider'] = $this->joinProviderKeys($data['metadata_providers'] ?? []);
        $data['attributes']['captured_by_user_id'] = $request->user()->id;

        try {
            $item = $this->mediaItemService->create($library, $data['attributes']);
        } catch (DuplicateEanException $e) {
            return response()->json(['message' => $e->getMessage(), 'ean' => $e->ean], 409);
        }

        if (! empty($data['cover_url'])) {
            $coverPath = $this->coverDownloadService->download($data['cover_url'], $library->media_type, $item->ean);

            if ($coverPath) {
                $item->update(['cover_path' => $coverPath]);
            }
        }

        return response()->json($item, 201);
    }

    /**
     * Re-runs the metadata lookup for an *already captured* item (GitHub
     * issue #56) — e.g. a provider failed on the original import, a new
     * plugin was enabled since, or the source data improved. Reuses
     * lookupMerged() (#48) keyed off the item's own stored EAN, so the
     * frontend gets back the exact same {candidates, merged, provider_statuses}
     * shape BulkImportService::resolveOne() already produces (#53) and can drive it
     * through the same MetadataMergeReview component the initial capture
     * flow uses, per explicit user instruction that this should offer the
     * same per-field picking rather than a blind overwrite.
     *
     * GitHub issue #155's follow-up: an item captured without a real EAN
     * (#151) stores a generated `NoEAN-...` placeholder — querying every
     * enabled provider by that value can only ever return `no_match`
     * (real API/scraping requests and, for the LLM providers, real
     * per-call cost, all wasted on a code that was never real to begin
     * with), so this short-circuits before any provider is even asked.
     * The frontend already disables MediaItemDetailDialog's "refresh"
     * button for exactly this case (see its own comment) — this is the
     * server-side backstop for a direct API call/stale page bypassing that.
     */
    public function refresh(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canWriteItems($request->user(), $library), 403);

        $record = $library->mediaItems()->findOrFail($item);

        if ($this->mediaItemService->isNoEanPlaceholder($record->ean)) {
            return response()->json(['status' => 'no_match', 'candidates' => [], 'merged' => ['fields' => [], 'covers' => []], 'provider_statuses' => []]);
        }

        $result = $this->importService->lookupMerged($library, $record->ean);

        return response()->json([
            'status' => empty($result['candidates']) ? 'no_match' : 'candidates',
            'candidates' => $result['candidates'],
            'merged' => $result['merged'],
            // GitHub issue #53.
            'provider_statuses' => $result['provider_statuses'],
        ]);
    }

    /**
     * Applies the field-by-field selections from a refresh() review onto
     * the existing item (GitHub issue #56) — the update-path counterpart
     * to import()'s create. A replaced cover follows the same
     * store-then-delete-old order uploadCover() already uses, so a failed
     * download never leaves the item without the cover it had before.
     */
    public function reimport(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canWriteItems($request->user(), $library), 403);

        $record = $library->mediaItems()->findOrFail($item);

        $data = $request->validate([
            'attributes' => ['required', 'array'],
            'cover_url' => ['nullable', 'string'],
            // GitHub issue #74 — see import()'s matching field for why this
            // isn't part of `attributes`. Unlike import(), capture_method is
            // deliberately NOT touched here: a metadata refresh doesn't
            // change how the item was originally captured, only where its
            // current field values came from.
            'metadata_providers' => ['nullable', 'array'],
            'metadata_providers.*' => ['string'],
        ]);

        $attributes = $this->stripInternallyManagedFields($data['attributes']);
        $attributes['metadata_provider'] = $this->joinProviderKeys($data['metadata_providers'] ?? []);

        $this->mediaItemService->updateFromMetadata($record, $attributes);

        if (! empty($data['cover_url'])) {
            $oldCoverPath = $record->cover_path;
            $coverPath = $this->coverDownloadService->download($data['cover_url'], $library->media_type, $record->ean);

            if ($coverPath) {
                $record->update(['cover_path' => $coverPath]);
                $this->coverDownloadService->delete($oldCoverPath);
            }
        }

        return response()->json($record->fresh());
    }

    /** Enable/disable a plugin or reorder it (briefing 15. — admin only, see routes/api.php). */
    public function updatePlugin(Request $request, MetadataPlugin $plugin)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer'],
            'config' => ['sometimes', 'array'],
        ]);

        $plugin->update($data);

        Log::info('Metadata plugin updated', ['actor_id' => $request->user()->id, 'provider_key' => $plugin->provider_key, 'changes' => $this->redactedChanges($plugin, $data)]);

        return $plugin;
    }

    /**
     * Security fix: `attributes` (import()/reimport() above) deliberately
     * passes through unvalidated per-key — see import()'s comment on why a
     * per-field Laravel validation rule can't be used here — since it needs
     * to carry every media-type-varying field a provider/the frontend's
     * merge-review picker supplies. But `cover_path`/`library_id` are
     * internally managed, not legitimate item data, and both are
     * mass-assignable on every MediaBook/MediaCd/MediaDvdBluray model. Left
     * in place, a caller could set `attributes.cover_path` to an arbitrary
     * path on the `local` disk (e.g. a backup archive under `backups/...`,
     * which MediaItemController::cover()/deleteCover() would then read or
     * delete on request — CoverDownloadService::isManagedPath() is this
     * same fix's second line of defense on that read/delete path), or
     * `attributes.library_id` to move the created/updated item into a
     * library the caller has no access to, bypassing
     * MediaItemController::move()'s own ownership/media-type checks
     * entirely. Stripped here, at the one boundary where this array stops
     * being "whatever a trusted provider/this same request's owner
     * supplied" and starts being persisted.
     */
    private function stripInternallyManagedFields(array $attributes): array
    {
        unset(
            $attributes['cover_path'], $attributes['library_id'],
            // GitHub issue #74: same reasoning as cover_path/library_id above
            // — these are set from server-computed facts about *this*
            // request (which endpoint, which authenticated user, the
            // separately-validated `metadata_providers` field), not from
            // caller-supplied item data, so a value smuggled in through the
            // otherwise-permissive `attributes` blob must never win.
            $attributes['capture_method'], $attributes['metadata_provider'], $attributes['captured_by_user_id'],
        );

        return $attributes;
    }

    /** GitHub issue #74 — comma-separated, matching the MediaDvdBluray::languages multi-value convention (see the migration that added metadata_provider). Empty/absent input becomes null, not an empty string, consistent with every other optional column here. */
    private function joinProviderKeys(array $providerKeys): ?string
    {
        $unique = array_values(array_unique(array_filter($providerKeys, fn ($key) => $key !== '')));

        return $unique === [] ? null : implode(',', $unique);
    }

    /**
     * $data['config'] as-is except any field the provider itself declares
     * `type: 'password'` (MetadataProviderConfigField, GitHub issue #29) —
     * the same authoritative "this is a secret" marker PluginsPage.tsx
     * already uses to render a masked input, reused here instead of a
     * hardcoded key-name list so any current or future provider's secret
     * field is covered automatically, not just today's `api_key`.
     */
    private function redactedChanges(MetadataPlugin $plugin, array $data): array
    {
        if (! isset($data['config']) || ! is_array($data['config'])) {
            return $data;
        }

        $secretKeys = collect($this->registry->configFieldsByProviderKey()->get($plugin->provider_key, []))
            ->filter(fn (array $field) => $field['type'] === 'password')
            ->pluck('key');

        foreach ($secretKeys as $key) {
            if (array_key_exists($key, $data['config']) && $data['config'][$key] !== null) {
                $data['config'][$key] = '[REDACTED]';
            }
        }

        return $data;
    }
}
