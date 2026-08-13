<?php

namespace App\Domain\Metadata\Contracts;

/**
 * Common interface every metadata source plugin implements (briefing 8.1).
 * The plugin system is the mechanism by which new sources (e.g. a future
 * "Comics" source, or an additional book source beyond the four listed in
 * 8.2) can be added without touching the core: implement this interface,
 * then register the class + a matching row in metadata_plugins
 * (see MetadataProviderRegistry).
 */
interface MetadataProviderInterface
{
    /**
     * Unique, stable key stored in metadata_plugins.provider_key
     * (e.g. "book.open_library", "cd.musicbrainz").
     */
    public function key(): string;

    /** Human-readable name shown in the admin plugin list (briefing 15.). */
    public function name(): string;

    /** One of 'book' | 'cd' | 'dvd_bluray' — the media type this plugin serves. */
    public function mediaType(): string;

    /**
     * Look up candidate metadata records for a scanned/entered EAN or ISBN
     * (briefing 8.3, step 1-2). Returns zero or more candidates; the caller
     * (MetadataImportService) merges results from all enabled providers for
     * the media type into one selection list (8.3, step 3).
     *
     * @return array<int, MetadataCandidate>
     */
    public function lookupByCode(string $code): array;

    /**
     * Free-text search, used when no exact code match was found or for
     * manual lookups.
     *
     * @return array<int, MetadataCandidate>
     */
    public function search(string $query): array;

    /**
     * Declares the admin-editable fields of this provider's
     * metadata_plugins.config JSON blob (briefing 8.1/15., GitHub issue
     * #29) so PluginsPage.tsx can render a real settings form instead of a
     * raw JSON textarea. Most providers need no configuration at all (an
     * API key, like UpcMdbProvider's, is the exception) and simply return
     * an empty array.
     *
     * @return MetadataProviderConfigField[]
     */
    public function configFields(): array;
}
