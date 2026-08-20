<?php

namespace Tests\Feature;

use App\Domain\Libraries\MediaItemService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #151: MediaItemService::generateNoEanPlaceholder() — a
 * `NoEAN-{13 random digits}` value for an item captured without a real,
 * known EAN (manual entry with the field left blank, or a metadata
 * candidate found via free-text search rather than an EAN/barcode
 * lookup). MediaItemController::store()/MetadataController::import()'s own
 * tests cover the two actual call sites end-to-end; this focuses on the
 * generator itself, including the retry-on-collision path a real HTTP
 * request could never reliably exercise (see randomNoEanCandidate()'s own
 * docblock for why the seam is split out the way it is).
 */
class MediaItemNoEanPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function library(int $ownerId): Library
    {
        return Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_generates_a_placeholder_matching_the_documented_format(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $placeholder = app(MediaItemService::class)->generateNoEanPlaceholder($library);

        $this->assertMatchesRegularExpression('/^NoEAN-\d{13}$/', $placeholder);
    }

    /**
     * A real PHP CSPRNG call can't be made to deterministically collide, so
     * this overrides randomNoEanCandidate() (the one seam
     * generateNoEanPlaceholder() calls into for a fresh guess) via an
     * anonymous subclass, rather than mocking random_int() itself — see
     * that method's own docblock.
     */
    public function test_regenerates_on_a_collision_with_an_existing_item_in_the_same_library(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Already Taken', 'ean' => 'NoEAN-0000000000001']);

        $service = new class extends MediaItemService
        {
            public int $calls = 0;

            protected function randomNoEanCandidate(): string
            {
                $this->calls++;

                // First guess collides with the pre-existing item above;
                // the second is free.
                return $this->calls === 1 ? 'NoEAN-0000000000001' : 'NoEAN-0000000000002';
            }
        };

        $placeholder = $service->generateNoEanPlaceholder($library);

        $this->assertSame('NoEAN-0000000000002', $placeholder);
        $this->assertSame(2, $service->calls);
    }

    /** The per-library scoping generateNoEanPlaceholder() shares with create()'s own duplicate check (briefing 5.1) — an identical placeholder already used in a *different* library is not a collision. */
    public function test_does_not_treat_a_placeholder_used_in_a_different_library_as_a_collision(): void
    {
        $owner = $this->actingAsUser();
        $libraryA = $this->library($owner->id);
        $libraryB = Library::query()->create(['name' => 'More Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'In Library A', 'ean' => 'NoEAN-0000000000001']);

        $service = new class extends MediaItemService
        {
            protected function randomNoEanCandidate(): string
            {
                return 'NoEAN-0000000000001';
            }
        };

        $this->assertSame('NoEAN-0000000000001', $service->generateNoEanPlaceholder($libraryB));
    }
}
