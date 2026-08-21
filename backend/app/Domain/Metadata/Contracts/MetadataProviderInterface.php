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

    /**
     * This provider class's own version, shown in the admin plugin list
     * (briefing 15., GitHub issue #44) — not stored in `metadata_plugins`,
     * computed fresh per request straight from the class, same pattern
     * configFields() already uses (see MetadataProviderRegistry::
     * versionsByProviderKey()). Every provider starts at "v1.0" as of this
     * feature's introduction, regardless of how long it already existed —
     * there is no prior version history to reconstruct. From then on, a
     * developer bumps this by hand whenever they change what the class
     * actually does (a newly-mapped field, a changed API endpoint, a
     * behavior fix) — usually the minor part (v1.0 -> v1.1); a
     * larger/breaking change bumps further. There's no reasonable way to
     * derive "how big a change was" automatically from a diff, so this is
     * a plain, human-maintained string, not a computed value — free-form
     * on purpose (not required to be strict semver), so e.g. a
     * lower-confidence/experimental provider can mark itself "v0.1-beta"
     * (see GitHub issue #50) without the interface getting in the way.
     */
    public function version(): string;

    /**
     * Whether this provider talks to a real, documented API ('api') or
     * scrapes a page not meant to be machine-read ('scraping') — shown in
     * the admin plugin list (briefing 15., GitHub issue #55) so an operator
     * can see the difference themselves instead of it only being
     * documented in source/GitHub issues. Same "attach live per request,
     * don't store in the database" pattern version() already established
     * (see MetadataProviderRegistry::sourceTypesByProviderKey()) — this is
     * an intrinsic property of how the class is implemented, not something
     * that changes at runtime. Scraping carries real, additional
     * downsides an API-based provider doesn't have — a documented ToS
     * risk, no success guarantee, and a much higher chance of silently
     * breaking on an undocumented markup change (see
     * AmazonScraping's docblock, GitHub issue #50) — which is exactly why
     * this needs to be visible, not just version()'s "-beta" suffix on its
     * own.
     *
     * A third value, 'llm' (GitHub issue #59), was added alongside the
     * original 'api'|'scraping' pair for the Claude-backed providers
     * (App\Domain\Metadata\Providers\Claude\ClaudeMetadataProvider) — an
     * LLM-backed source is neither a documented third-party API nor a
     * scrape of a page not meant to be machine-read; it carries its own,
     * different risk profile (hallucination, per-call cost) that the
     * other two labels don't capture.
     */
    public function sourceType(): string;

    /**
     * Whether lookupByCode() above can ever meaningfully return a result
     * for this provider — shown as a checkmark column in the admin plugin
     * list (briefing 8.1/15., GitHub issue #158) so an operator can see at
     * a glance which providers only ever contribute through search(), the
     * same "attach live per request, don't store in the database" pattern
     * version()/sourceType() already established (see
     * MetadataProviderRegistry::eanSupportByProviderKey()) — this is an
     * intrinsic property of the source itself, not something that changes
     * at runtime or is admin-configurable.
     *
     * Declared explicitly by each provider rather than inferred from
     * lookupByCode()'s behavior (e.g. "does it always return []?"), since
     * an empty result there can also just mean "no match for this code",
     * which is not the same fact. Every provider implemented so far
     * genuinely supports a code-based lookup and returns `true`; GitHub
     * issue #157 (a TMDB provider — the movie database has no barcode/EAN
     * lookup capability at all, confirmed against its own API reference)
     * is expected to be the first real `false`.
     */
    public function supportsCodeLookup(): bool;
}
