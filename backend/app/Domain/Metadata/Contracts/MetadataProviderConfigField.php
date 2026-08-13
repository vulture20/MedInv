<?php

namespace App\Domain\Metadata\Contracts;

/**
 * Declares one admin-editable field of a provider's `metadata_plugins.config`
 * JSON blob (briefing 8.1/15., GitHub issue #29) — this is what lets
 * PluginsPage.tsx render a real per-plugin settings form (one input per
 * field) instead of a raw JSON textarea, without the frontend needing to
 * know each provider's config shape ahead of time.
 *
 * Deliberately carries no display label: like every other backend-defined
 * enum this app hands to the frontend (e.g. Library.media_type), only the
 * stable `key` crosses the API boundary — the frontend resolves it to a
 * translated label via i18n (`admin.pluginConfig.fields.<key>`), falling
 * back to a humanized version of the key itself for a provider whose
 * fields haven't been translated yet, so a new provider's config still
 * renders something reasonable on day one.
 */
final class MetadataProviderConfigField
{
    /**
     * @param  string  $key  Property name inside metadata_plugins.config, e.g. "api_key".
     * @param  'text'|'password'  $type  'password' is rendered (and treated) as masked input — for secrets like an API key.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'text',
        public readonly bool $required = false,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'required' => $this->required,
        ];
    }
}
