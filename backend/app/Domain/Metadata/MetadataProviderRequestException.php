<?php

namespace App\Domain\Metadata;

use RuntimeException;

/**
 * Thrown by a MetadataProviderInterface implementation when its request to
 * the provider itself did not succeed (non-2xx HTTP response, a missing
 * required API key, ...) — as opposed to a request that succeeded but
 * simply found nothing for the given code/query.
 *
 * GitHub issue #53: MetadataImportService::collectCandidatesByCode() catches
 * this (like any other \Throwable) and reports the provider's status as
 * 'failed' rather than 'no_match', so a misconfigured API key, a rate
 * limit, or a blocked scraper (e.g. the Amazon ones from #50) is
 * distinguishable from the provider genuinely having no match — previously
 * both looked identical to the user, with the actual failure reaching only
 * Log::warning server-side. Providers are not required to use this
 * exception specifically (the catch is `\Throwable`, not this class), but
 * it's the deliberate choice for "the request itself didn't succeed" over
 * silently returning an empty result, which every provider used to do
 * before this change.
 */
class MetadataProviderRequestException extends RuntimeException {}
