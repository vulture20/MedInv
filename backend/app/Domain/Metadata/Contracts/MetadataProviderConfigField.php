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
     * @param  'text'|'password'|'textarea'|'select'  $type  'password' is rendered (and treated) as masked input, for secrets like an API key. 'textarea' (GitHub issue #59) is a multi-line `<textarea>` instead of a single-line `<input>` — for a field like a customizable LLM prompt, where a one-line input would truncate the field visually and make editing painful. 'select' (GitHub issue #210) is a `<select>` dropdown constrained to $options — for a closed, small set of admin-chosen values (e.g. Amazon's marketplace/country) where a free-text input would let an admin type an unsupported value.
     * @param  ?string  $default  Pre-fills PluginsPage.tsx's settings-form field when `metadata_plugins.config` doesn't already have a value for this key (i.e. the admin hasn't customized it yet) — e.g. a provider's suggested default prompt (issue #59's addendum). Distinct from `required`: a field can have both a sensible default *and* still be required, in which case the pre-filled default already satisfies that requirement until an admin actually clears it.
     * @param  ?array<int, string>  $options  Only meaningful (and required in practice) for `type: 'select'` — the closed set of values the field may hold. Like $key itself, only the raw values cross the API boundary; PluginsPage.tsx resolves each one's display label via i18n (`admin.pluginConfig.fieldOptions.<key>.<value>`).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'text',
        public readonly bool $required = false,
        public readonly ?string $default = null,
        public readonly ?array $options = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }
}
