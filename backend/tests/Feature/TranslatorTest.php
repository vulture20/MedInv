<?php

namespace Tests\Feature;

use App\Domain\Languages\Translator;
use App\Models\LanguagePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #113 — App\Domain\Languages\Translator is what makes PDF
 * exports render in the requesting user's language, by looking translated
 * strings up against the exact same data the frontend uses: the two
 * bundled locale JSON files for 'en'/'de', and the `language_packs` table
 * for every other installed language. See PdfExportService's own feature
 * tests (LibraryPdfExportTest/ReportsPdfExportTest) for end-to-end
 * coverage of the PDFs themselves; this file is Translator in isolation.
 */
class TranslatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_a_key_from_the_bundled_english_locale_file(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('Title', $translator->get('en', 'mediaItem.fields.title'));
    }

    public function test_it_resolves_a_key_from_the_bundled_german_locale_file(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('Titel', $translator->get('de', 'mediaItem.fields.title'));
    }

    public function test_it_resolves_a_key_from_a_database_backed_language_pack(): void
    {
        LanguagePack::query()->create([
            'code' => 'xx',
            'name' => 'Test Language',
            'translations' => ['mediaItem' => ['fields' => ['title' => 'Xitle']]],
        ]);
        $translator = app(Translator::class);

        $this->assertSame('Xitle', $translator->get('xx', 'mediaItem.fields.title'));
    }

    /** An unknown or since-deleted language pack falls back to English rather than breaking the export — see Translator::load()'s own docblock. */
    public function test_it_falls_back_to_english_for_an_unknown_language_code(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('Title', $translator->get('nonexistent', 'mediaItem.fields.title'));
    }

    /** A genuinely missing key (typo, or a language pack that never got a newer key) falls back through English before finally returning the raw key itself, rather than throwing. */
    public function test_it_falls_back_to_the_raw_key_when_even_english_lacks_it(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('some.made.up.key', $translator->get('en', 'some.made.up.key'));
    }

    public function test_interpolation_replaces_placeholders_with_the_given_values(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('generated 2026-08-19 12:00', $translator->get('en', 'pdfExport.generatedAt', ['date' => '2026-08-19 12:00']));
    }

    /**
     * i18next-style pluralization (`${key}_one`/`${key}_other`) — GitHub
     * issue #113's own investigation confirmed every bundled language pack
     * only ever defines these two forms, even for languages with richer
     * native plural grammar (pl/ru/uk), so `plural()` only needs to
     * distinguish `count === 1` from everything else.
     */
    public function test_plural_picks_the_singular_form_for_a_count_of_one(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('1 item', $translator->plural('en', 'libraries.itemsTitle', 1));
    }

    public function test_plural_picks_the_plural_form_for_any_other_count(): void
    {
        $translator = app(Translator::class);

        $this->assertSame('0 items', $translator->plural('en', 'libraries.itemsTitle', 0));
        $this->assertSame('3 items', $translator->plural('en', 'libraries.itemsTitle', 3));
    }
}
