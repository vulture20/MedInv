<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #43: the export filename used to always be
 * "medinv-export-{timestamp}.zip" regardless of what was actually
 * exported, making several downloads indistinguishable by filename alone.
 * ExportImportController::export() now embeds the exported library
 * name(s), or the literal word "all" for an "alle" export (briefing 9.1) —
 * deliberately not translated, and generated entirely server-side (not
 * client-constructed) per explicit product decision.
 */
class ExportFilenameTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Symfony's Content-Disposition only quotes/percent-encodes the
     * filename when it actually needs to: a plain-ASCII name comes back as
     * a bare, unquoted `filename=...`, while a non-ASCII one additionally
     * gets an RFC 5987 `filename*=utf-8''...` (percent-encoded, the real
     * name) alongside an ASCII-transliterated `filename=` fallback — prefer
     * the starred one when present, same as a correct client must (see the
     * matching fix in ExportImportPage.tsx).
     */
    private function filenameOf($response): string
    {
        $disposition = $response->headers->get('Content-Disposition');

        if (preg_match("/filename\*=utf-8''([^;]+)/i", $disposition, $matches)) {
            return rawurldecode($matches[1]);
        }

        return preg_match('/filename="?([^";]+)"?/', $disposition, $matches) ? $matches[1] : '';
    }

    public function test_exporting_everything_uses_the_literal_word_all_not_a_translation(): void
    {
        $admin = $this->actingAsAdmin();
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);

        $response = $this->postJson('/api/admin/export', []);

        $this->assertStringContainsString('medinv-export-all-', $this->filenameOf($response));
    }

    public function test_exporting_a_single_library_embeds_its_name(): void
    {
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);

        $response = $this->postJson('/api/admin/export', ['library_ids' => [$library->id]]);

        $this->assertStringContainsString('medinv-export-Novels-', $this->filenameOf($response));
    }

    public function test_exporting_several_libraries_joins_their_names_with_underscores(): void
    {
        $admin = $this->actingAsAdmin();
        $novels = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        $cds = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $admin->id]);

        $response = $this->postJson('/api/admin/export', ['library_ids' => [$novels->id, $cds->id]]);

        $this->assertStringContainsString('medinv-export-Novels_CDs-', $this->filenameOf($response));
    }

    public function test_special_characters_and_spaces_in_a_library_name_are_sanitized(): void
    {
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Sample Library – CDs/Vinyl?', 'media_type' => 'cd', 'owner_id' => $admin->id]);

        $response = $this->postJson('/api/admin/export', ['library_ids' => [$library->id]]);

        $filename = $this->filenameOf($response);
        $this->assertStringContainsString('medinv-export-Sample-Library-CDs-Vinyl-', $filename);
        // No raw slash/question mark ended up in the filename itself.
        $this->assertMatchesRegularExpression('/^medinv-export-[\w-]+\.zip$/', $filename);
    }

    /** A non-ASCII library name (this app ships a dozen language packs, briefing 10./11.4) keeps its letters rather than being reduced to nothing/ASCII transliteration. */
    public function test_unicode_letters_in_a_library_name_are_preserved(): void
    {
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Bücher', 'media_type' => 'book', 'owner_id' => $admin->id]);

        $response = $this->postJson('/api/admin/export', ['library_ids' => [$library->id]]);

        $this->assertStringContainsString('medinv-export-Bücher-', $this->filenameOf($response));
    }

    /** A specific (if odd) selection that matches nothing must not be mislabeled "all". */
    public function test_a_selection_matching_no_library_is_not_mislabeled_all(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/export', ['library_ids' => [999999]]);

        $filename = $this->filenameOf($response);
        $this->assertStringContainsString('medinv-export-export-', $filename);
        $this->assertStringNotContainsString('-all-', $filename);
    }
}
