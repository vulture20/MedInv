<?php

namespace App\Domain\Metadata\Contracts;

use App\Domain\Metadata\MetadataProviderRequestException;

/**
 * Optional capability a MetadataProviderInterface implementation can also
 * declare (GitHub issue #160): whether a candidate set of config values —
 * typically an API key/token an admin just typed into PluginsPage.tsx's
 * settings dialog, not necessarily what's already saved to
 * `metadata_plugins.config` — actually works, checked with a real, cheap
 * request against the provider's own API rather than only ever being
 * found out the hard way at the next real capture attempt (a wrong key
 * silently looks identical to a genuine "no_match"/"failed").
 *
 * Deliberately not part of MetadataProviderInterface itself and not
 * implemented by every provider: a real connectivity check is meaningful
 * for very different reasons per provider (a wrong API key vs. no
 * credential concept at all, e.g. OpenLibrary/MusicBrainz, vs. a genuine
 * per-call cost the LLM providers carry, where "testing" a key by
 * spending a real generation request would work against this app's own
 * documented carefulness about that cost) — a provider implements this
 * only when it actually has a cheap, side-effect-free way to validate its
 * own credentials, not by default.
 */
interface TestableMetadataProvider
{
    /**
     * @param  array<string, mixed>  $config  Candidate config values to test — the same shape configFields() describes, but not necessarily already persisted.
     * @return bool Whether the given config's credentials are valid.
     *
     * @throws MetadataProviderRequestException If the check itself couldn't be completed (network error, an unexpected status neither confirms nor rejects the credentials) — distinct from "credentials are wrong", the same distinction GitHub issue #53 already draws elsewhere in this app between a genuine no-match and a request failure.
     */
    public function testConfig(array $config): bool;
}
