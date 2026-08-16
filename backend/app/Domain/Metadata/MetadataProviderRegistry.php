<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Book\GoogleBooksProvider;
use App\Domain\Metadata\Providers\Book\HardcoverProvider;
use App\Domain\Metadata\Providers\Book\OpenLibraryProvider;
use App\Domain\Metadata\Providers\Cd\DiscogsProvider;
use App\Domain\Metadata\Providers\Cd\MusicBrainzProvider;
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
    /** @return class-string<MetadataProviderInterface>[] */
    public static function defaultProviders(): array
    {
        return [
            OpenLibraryProvider::class,
            GoogleBooksProvider::class,
            HardcoverProvider::class,
            // TODO: AmazonBookProvider (briefing 8.2 — Buch)
            MusicBrainzProvider::class,
            DiscogsProvider::class,
            // TODO: AmazonCdProvider (briefing 8.2 — CD)
            UpcMdbProvider::class,
            // TODO: AmazonDvdBlurayProvider, EmunationProvider (briefing 8.2 — DVD/Blu-ray)
        ];
    }

    /** Ensures every default provider has a corresponding metadata_plugins row. */
    public function syncToDatabase(): void
    {
        foreach (static::defaultProviders() as $class) {
            /** @var MetadataProviderInterface $provider */
            $provider = app($class);

            MetadataPlugin::query()->firstOrCreate(
                ['provider_key' => $provider->key()],
                ['name' => $provider->name(), 'media_type' => $provider->mediaType(), 'enabled' => true],
            );
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
