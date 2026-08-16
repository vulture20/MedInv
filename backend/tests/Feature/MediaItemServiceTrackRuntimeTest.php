<?php

namespace Tests\Feature;

use App\Domain\Libraries\MediaItemService;
use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #48: MediaItemService::create() derives a CD's
 * `runtime_seconds`/`runtime_computed` from `tracks` — the single, central
 * point every creation path (manual entry, bulk capture, metadata import,
 * backup/export restore) funnels through. Deriving it here, once, from
 * whichever `tracks` value actually ends up in the submitted attributes —
 * rather than letting providers report a runtime independently of their own
 * tracks — is what guarantees the two can never end up mismatched (e.g. one
 * provider's tracks paired with a different provider's runtime number).
 */
class MediaItemServiceTrackRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private function cdLibrary(): Library
    {
        $owner = User::factory()->create();

        return Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
    }

    public function test_runtime_is_computed_by_summing_every_tracks_duration(): void
    {
        $item = app(MediaItemService::class)->create($this->cdLibrary(), [
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [
                ['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284],
                ['position' => '2', 'title' => 'Paranoid Android', 'duration_seconds' => 383],
            ],
        ]);

        $this->assertSame(667, $item->runtime_seconds);
        $this->assertTrue($item->runtime_computed);
    }

    public function test_an_explicitly_provided_runtime_is_never_overwritten_by_a_derived_one(): void
    {
        $item = app(MediaItemService::class)->create($this->cdLibrary(), [
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284]],
            'runtime_seconds' => 9999,
            'runtime_computed' => false,
        ]);

        $this->assertSame(9999, $item->runtime_seconds);
        $this->assertFalse($item->runtime_computed);
    }

    public function test_no_runtime_is_derived_when_any_tracks_duration_is_unknown(): void
    {
        $item = app(MediaItemService::class)->create($this->cdLibrary(), [
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [
                ['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284],
                ['position' => '2', 'title' => 'Paranoid Android', 'duration_seconds' => null],
            ],
        ])->fresh();

        $this->assertNull($item->runtime_seconds);
        $this->assertFalse($item->runtime_computed);
    }

    public function test_no_tracks_key_at_all_leaves_runtime_untouched(): void
    {
        $item = app(MediaItemService::class)->create($this->cdLibrary(), [
            'title' => 'OK Computer',
            'ean' => '724385522925',
        ])->fresh();

        $this->assertNull($item->runtime_seconds);
        $this->assertFalse($item->runtime_computed);
    }

    public function test_an_empty_tracks_array_leaves_runtime_untouched(): void
    {
        $item = app(MediaItemService::class)->create($this->cdLibrary(), [
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [],
        ])->fresh();

        $this->assertNull($item->runtime_seconds);
        $this->assertFalse($item->runtime_computed);
    }

    /** Confirms this is entirely data-driven, not a media_type branch — a book/DVD item with no 'tracks'-shaped fillable columns at all must not error. */
    public function test_a_non_cd_item_is_unaffected(): void
    {
        $owner = User::factory()->create();
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $item = app(MediaItemService::class)->create($library, ['title' => '1984', 'ean' => '9780451524935']);

        $this->assertSame('1984', $item->title);
    }
}
