<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * GitHub issue #71: without this, a test's `Http::fake()` covering some
     * but not all of a provider's real outgoing calls doesn't fail loudly —
     * the uncovered call just goes out as a genuine network request. In a
     * sandbox that can reach the real host, that's mostly silent and slow;
     * the moment reachability changes (confirmed live: this sandbox lost
     * reachability to openlibrary.org mid-session), it turns into a
     * confusing test failure several layers away from the actual cause
     * (MetadataRefreshTest's `status: 'candidates'` assertion failing with
     * no hint that a stray, unfaked `Http::get()` to a *different* URL was
     * ever involved — see MetadataRefreshTest's own fix for the concrete
     * case this surfaced). Enabling this globally turns any future instance
     * of the same mistake into an immediate, explicit "stray request"
     * failure at the point it happens, in whichever test introduces it,
     * rather than an occasional, hard-to-place flake. Verified live against
     * the full suite before adding this: all 744 tests already passed with
     * it enabled, meaning nothing here actually depended on a real,
     * unfaked network call to begin with.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
