<?php

namespace App\Domain\Metadata\Contracts;

/**
 * Optional marker (GitHub issue #192, following a requested feasibility
 * study) for a MetadataProviderInterface implementation whose
 * lookupByCode() genuinely works — unlike a `supportsCodeLookup() === false`
 * provider (GitHub issue #158) — but only against a generic, non-media-
 * specific barcode database (e.g. upcitemdb.com's broad consumer-goods
 * catalog), not a source whose result belongs on equal footing with a real
 * book/CD/DVD-specific match.
 *
 * A provider implementing this (alongside MetadataProviderInterface, the
 * same "check via `instanceof`, not a new interface method every existing
 * provider would have to mechanically implement" shape
 * TestableMetadataProvider already established for a rare, optional
 * capability) is queried by MetadataImportService::collectCandidatesByCode()
 * only as a last resort: when every ordinary, non-fallback code-capable
 * provider for the media type found nothing at all in round 1. Its result
 * still flows into the same candidate/merge pipeline as everything else —
 * so its title is available to seed GitHub issue #159's round 2 (title-only
 * providers), and so a user isn't left with a flat "no match" when a
 * fallback provider happens to be the only source that recognized the code
 * at all — but is always reported with its own `stage: 'fallback'`
 * provider_statuses entry, so MetadataMergeReview.tsx can visibly label it
 * as a lower-confidence, generic-database result rather than letting it
 * look identical to an ordinary EAN match from a real media-specific
 * source.
 *
 * A pure marker with no methods of its own — there is nothing to declare
 * beyond "queried last, shown as such"; the actual lookup still goes
 * through the ordinary lookupByCode()/search() methods every provider
 * already implements.
 */
interface NameOnlyFallbackProvider {}
