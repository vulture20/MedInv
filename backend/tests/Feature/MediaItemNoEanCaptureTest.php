<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #151: capturing a media item without a real, known EAN.
 * `ean` is now optional on both entry points a genuinely new item can be
 * created through — the standalone manual-entry form
 * (MediaItemController::store()) and confirming a metadata candidate
 * (MetadataController::import(), the endpoint a free-text search result —
 * as opposed to an EAN/barcode lookup — is confirmed through). Either one
 * now falls back to a generated `NoEAN-{13 random digits}` placeholder
 * instead of rejecting the request; see
 * MediaItemService::generateNoEanPlaceholder()'s own docblock for the
 * generator itself and MediaItemNoEanPlaceholderTest for its own coverage.
 */
class MediaItemNoEanCaptureTest extends TestCase
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
        return Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_manual_entry_with_no_ean_gets_a_generated_placeholder(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'A Book With No Barcode']);

        $response->assertCreated();
        $item = MediaBook::query()->where('title', 'A Book With No Barcode')->firstOrFail();
        $this->assertMatchesRegularExpression('/^NoEAN-\d{13}$/', $item->ean);
    }

    /** An explicitly empty string is treated the same as an omitted field, not as "the EAN is a blank string" (a form field left empty submits `""`, not nothing at all). */
    public function test_manual_entry_with_an_empty_ean_string_also_gets_a_placeholder(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'Another Book', 'ean' => '']);

        $response->assertCreated();
        $item = MediaBook::query()->where('title', 'Another Book')->firstOrFail();
        $this->assertMatchesRegularExpression('/^NoEAN-\d{13}$/', $item->ean);
    }

    public function test_manual_entry_with_a_real_ean_still_uses_it_unchanged(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'Dune', 'ean' => '9780000000001']);

        $response->assertCreated();
        $item = MediaBook::query()->where('title', 'Dune')->firstOrFail();
        $this->assertSame('9780000000001', $item->ean);
    }

    /**
     * GitHub issue #151's actual motivating case: a candidate from
     * MetadataProviderInterface::search() (free-text search, unlike
     * lookupByCode()) always reports `ean: null` — this used to be
     * rejected outright with a 422 (see MetadataController::import()'s
     * git history), which is exactly what made "capture without an EAN"
     * impossible even via the metadata-search path.
     */
    public function test_confirming_a_metadata_candidate_with_no_ean_gets_a_generated_placeholder(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['title' => 'Found By Title Search', 'ean' => null],
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('title', 'Found By Title Search')->firstOrFail();
        $this->assertMatchesRegularExpression('/^NoEAN-\d{13}$/', $item->ean);
    }

    public function test_confirming_a_metadata_candidate_with_a_real_ean_still_uses_it_unchanged(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['title' => 'Dune', 'ean' => '9780000000002'],
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('title', 'Dune')->firstOrFail();
        $this->assertSame('9780000000002', $item->ean);
    }
}
