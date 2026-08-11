<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Book\OpenLibraryProvider;
use App\Domain\Metadata\Providers\Cd\MusicBrainzProvider;
use App\Domain\Metadata\Providers\DvdBluray\UpcItemDbProvider;
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
            // TODO: HardcoverProvider, AmazonBookProvider, GoogleBooksProvider (briefing 8.2 — Buch)
            MusicBrainzProvider::class,
            // TODO: AmazonCdProvider, DiscogsProvider (briefing 8.2 — CD)
            UpcItemDbProvider::class,
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

    /** Enabled provider instances for the given media type, ordered by admin-configured priority. */
    public function enabledProvidersFor(string $mediaType): Collection
    {
        $enabledKeys = MetadataPlugin::query()
            ->where('media_type', $mediaType)
            ->where('enabled', true)
            ->orderBy('priority')
            ->pluck('provider_key');

        return collect(static::defaultProviders())
            ->map(fn (string $class) => app($class))
            ->filter(fn (MetadataProviderInterface $provider) => $enabledKeys->contains($provider->key()))
            ->sortBy(fn (MetadataProviderInterface $provider) => $enabledKeys->search($provider->key()))
            ->values();
    }
}
