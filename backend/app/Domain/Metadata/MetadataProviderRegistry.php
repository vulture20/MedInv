<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Book\AmazonBookProvider;
use App\Domain\Metadata\Providers\Book\ClaudeBookProvider;
use App\Domain\Metadata\Providers\Book\GeminiBookProvider;
use App\Domain\Metadata\Providers\Book\GoogleBooksProvider;
use App\Domain\Metadata\Providers\Book\HardcoverProvider;
use App\Domain\Metadata\Providers\Book\OpenAiBookProvider;
use App\Domain\Metadata\Providers\Book\OpenLibraryProvider;
use App\Domain\Metadata\Providers\Cd\AmazonCdProvider;
use App\Domain\Metadata\Providers\Cd\ClaudeCdProvider;
use App\Domain\Metadata\Providers\Cd\DiscogsProvider;
use App\Domain\Metadata\Providers\Cd\GeminiCdProvider;
use App\Domain\Metadata\Providers\Cd\MusicBrainzProvider;
use App\Domain\Metadata\Providers\Cd\OpenAiCdProvider;
use App\Domain\Metadata\Providers\DvdBluray\AmazonDvdBlurayProvider;
use App\Domain\Metadata\Providers\DvdBluray\ClaudeDvdBlurayProvider;
use App\Domain\Metadata\Providers\DvdBluray\GeminiDvdBlurayProvider;
use App\Domain\Metadata\Providers\DvdBluray\OpenAiDvdBlurayProvider;
use App\Domain\Metadata\Providers\DvdBluray\UpcMdbProvider;
use App\Models\MetadataPlugin;
use Illuminate\Support\Collection;

/**
 * Central plugin registry (briefing 8.1). New sources are added by
 * implementing MetadataProviderInterface and listing the class here —
 * nothing else in the core needs to change. `metadata_plugins` (synced via
 * syncToDatabase()) is what admins actually toggle on/off (15.); this class
 * is the compile-time list of classes available to be toggled.
 */
class MetadataProviderRegistry
{
    /**
     * Provider keys that must stay *disabled* until an admin explicitly
     * turns them on, unlike every other default provider (GitHub issue
     * #50): the three Amazon scrapers are Beta and carry a real ToS/legal
     * consideration (see AmazonScraping's docblock) that no other source
     * in this app has — enabling scraping traffic against a third party on
     * an operator's behalf, silently, just because they installed MedInv,
     * would be presumptuous in a way "on by default" isn't for a
     * documented public API. See syncToDatabase() below for where this is
     * actually applied.
     *
     * The three Claude providers (GitHub issue #59), the three
     * OpenAI-backed ones (GitHub issue #65), and the three Gemini-backed
     * ones (GitHub issue #66) — offering the same LLM-as-metadata-source
     * concept via a second and third vendor — are added here for a
     * related but distinct reason: an LLM-backed source costs real money
     * per lookup (unlike every non-Beta provider above, which is free or
     * has a generous free tier) and carries a hallucination risk that, per
     * #59's own proposal, is exactly why it shouldn't turn on just
     * because an admin installed/updated MedInv — a wrong, invented detail
     * is quieter and easier to miss than a plain "no match".
     */
    private const DEFAULT_DISABLED_PROVIDER_KEYS = [
        'book.amazon', 'cd.amazon', 'dvd_bluray.amazon',
        'book.claude', 'cd.claude', 'dvd_bluray.claude',
        'book.openai', 'cd.openai', 'dvd_bluray.openai',
        'book.gemini', 'cd.gemini', 'dvd_bluray.gemini',
    ];

    /** @return class-string<MetadataProviderInterface>[] */
    public static function defaultProviders(): array
    {
        return [
            OpenLibraryProvider::class,
            GoogleBooksProvider::class,
            HardcoverProvider::class,
            AmazonBookProvider::class,
            ClaudeBookProvider::class,
            OpenAiBookProvider::class,
            GeminiBookProvider::class,
            MusicBrainzProvider::class,
            DiscogsProvider::class,
            AmazonCdProvider::class,
            ClaudeCdProvider::class,
            OpenAiCdProvider::class,
            GeminiCdProvider::class,
            UpcMdbProvider::class,
            AmazonDvdBlurayProvider::class,
            ClaudeDvdBlurayProvider::class,
            OpenAiDvdBlurayProvider::class,
            GeminiDvdBlurayProvider::class,
            // TODO: EmunationProvider (briefing 8.2 — DVD/Blu-ray)
        ];
    }

    /**
     * Ensures every default provider has a corresponding metadata_plugins
     * row — enabled by default, except DEFAULT_DISABLED_PROVIDER_KEYS
     * (GitHub issue #50), which an admin must explicitly opt into.
     *
     * `name`/`media_type` are kept in sync with the provider class on every
     * call, not just at row-creation time: unlike `enabled`/`priority`/
     * `config` (admin-controlled via PUT /admin/metadata/plugins/{id},
     * PluginsPage.tsx never sends `name` or `media_type`), these two are
     * entirely code-derived, so a provider's name() changing in code (e.g.
     * the Amazon providers' "(Beta)" suffix removal — name() itself was
     * already fixed, but `firstOrCreate()` alone never touches a row that
     * already existed from before that fix, since `db:seed --force` runs
     * on every container boot per docker/entrypoint.sh) should reach every
     * existing install's stored row on its next boot too, not just a fresh
     * one's.
     */
    public function syncToDatabase(): void
    {
        foreach (static::defaultProviders() as $class) {
            /** @var MetadataProviderInterface $provider */
            $provider = app($class);

            $plugin = MetadataPlugin::query()->firstOrCreate(
                ['provider_key' => $provider->key()],
                [
                    'name' => $provider->name(),
                    'media_type' => $provider->mediaType(),
                    'enabled' => ! in_array($provider->key(), self::DEFAULT_DISABLED_PROVIDER_KEYS, true),
                ],
            );

            if ($plugin->name !== $provider->name() || $plugin->media_type !== $provider->mediaType()) {
                $plugin->update([
                    'name' => $provider->name(),
                    'media_type' => $provider->mediaType(),
                ]);
            }
        }
    }

    /**
     * Every registered provider's declared config fields (GitHub issue #29),
     * keyed by provider_key — what MetadataController::plugins() attaches to
     * each metadata_plugins row so PluginsPage.tsx can render a settings
     * form per plugin without knowing provider shapes ahead of time.
     *
     * @return Collection<string, array>
     */
    public function configFieldsByProviderKey(): Collection
    {
        return collect(static::defaultProviders())
            ->map(fn (string $class) => app($class))
            ->mapWithKeys(fn (MetadataProviderInterface $provider) => [
                $provider->key() => collect($provider->configFields())->map->toArray()->all(),
            ]);
    }

    /**
     * Every registered provider's declared version (GitHub issue #44),
     * keyed by provider_key — same "attach live per request, don't store
     * in the database" shape as configFieldsByProviderKey() above, per the
     * issue's own explicit choice between its two proposed approaches:
     * MetadataProviderRegistry::syncToDatabase() never touches this at
     * all, so bumping a provider's version() is a plain code change with
     * no migration or sync step needed to actually take effect.
     *
     * @return Collection<string, string>
     */
    public function versionsByProviderKey(): Collection
    {
        return collect(static::defaultProviders())
            ->map(fn (string $class) => app($class))
            ->mapWithKeys(fn (MetadataProviderInterface $provider) => [
                $provider->key() => $provider->version(),
            ]);
    }

    /**
     * Every registered provider's declared source type ('api'|'scraping',
     * GitHub issue #55), keyed by provider_key — same "attach live per
     * request, don't store in the database" shape versionsByProviderKey()
     * already established, for the same reason: this is intrinsic to how
     * the class is implemented, not admin-configurable state.
     *
     * @return Collection<string, string>
     */
    public function sourceTypesByProviderKey(): Collection
    {
        return collect(static::defaultProviders())
            ->map(fn (string $class) => app($class))
            ->mapWithKeys(fn (MetadataProviderInterface $provider) => [
                $provider->key() => $provider->sourceType(),
            ]);
    }

    /**
     * Enabled provider instances for the given media type, ordered by
     * admin-configured priority. `orderBy('id')` after `priority` is the
     * same deterministic-tie-breaker fix as MetadataController::plugins()'s
     * — every provider starts at the same default priority (0) until an
     * admin reorders something, so without a secondary key which provider
     * actually gets tried first on a fresh install would be undefined
     * rather than merely a display-order quirk.
     */
    public function enabledProvidersFor(string $mediaType): Collection
    {
        $enabledKeys = MetadataPlugin::query()
            ->where('media_type', $mediaType)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->pluck('provider_key');

        return collect(static::defaultProviders())
            ->map(fn (string $class) => app($class))
            ->filter(fn (MetadataProviderInterface $provider) => $enabledKeys->contains($provider->key()))
            ->sortBy(fn (MetadataProviderInterface $provider) => $enabledKeys->search($provider->key()))
            ->values();
    }
}
