<?php

namespace App\Domain\Metadata\Contracts;

/**
 * One search/lookup result returned by a MetadataProviderInterface
 * implementation. `attributes` uses the same keys as the target media
 * type's fillable fields (e.g. App\Models\MediaBook), so a chosen candidate
 * can be merged straight into the record-creation form (briefing 8.3, step
 * 6) without per-provider mapping in the controller.
 */
final class MetadataCandidate
{
    /**
     * @param  array<string, mixed>  $attributes  Media attributes, keyed like the target model's fillable fields.
     * @param  string[]  $coverUrls  Candidate cover images to choose from (briefing 8.3, step 5).
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $sourceId,
        public readonly array $attributes,
        public readonly array $coverUrls = [],
    ) {}

    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'source_id' => $this->sourceId,
            'attributes' => $this->attributes,
            'cover_urls' => $this->coverUrls,
        ];
    }
}
