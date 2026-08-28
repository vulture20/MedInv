<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #7: StatisticsService used to return per-library counts/value
 * only — this covers the genre/language/year/publisher-artist-director
 * breakdowns from briefing 14. added on top.
 */
class StatisticsDistributionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function statsFor(int $libraryId): array
    {
        $response = $this->getJson('/api/statistics');
        $response->assertOk();

        return collect($response->json())->firstWhere('library_id', $libraryId);
    }

    public function test_book_library_reports_genre_language_publisher_and_year_distributions(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $user->id]);

        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001',
            'genre' => 'Sci-Fi', 'language' => 'de', 'publisher' => 'Acme', 'release_date' => '2020-05-01',
        ]);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'B', 'ean' => '1000000000002',
            'genre' => 'Sci-Fi', 'language' => 'en', 'publisher' => 'Acme', 'release_date' => '2020-11-01',
        ]);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'C', 'ean' => '1000000000003',
            'genre' => 'Fantasy', 'language' => 'de', 'publisher' => null, 'release_date' => '2021-01-01',
        ]);
        // No genre/language/publisher/release_date at all — must not appear in any distribution.
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'D', 'ean' => '1000000000004']);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['Sci-Fi' => 2, 'Fantasy' => 1], $stats['distributions']['genre']);
        $this->assertSame(['de' => 2, 'en' => 1], $stats['distributions']['language']);
        $this->assertSame(['Acme' => 2], $stats['distributions']['publisher']);
        $this->assertSame(['2020' => 2, '2021' => 1], $stats['distributions']['year']);
    }

    /** GitHub issue #206: `year` is now sorted by count like every other distribution, not chronologically — a middle year with the most items must come first. */
    public function test_book_library_sorts_the_year_distribution_by_count_not_chronologically(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $user->id]);

        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000005', 'release_date' => '2018-01-01']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '1000000000006', 'release_date' => '2019-01-01']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'C', 'ean' => '1000000000007', 'release_date' => '2019-06-01']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'D', 'ean' => '1000000000008', 'release_date' => '2019-11-01']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'E', 'ean' => '1000000000009', 'release_date' => '2020-01-01']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'F', 'ean' => '1000000000010', 'release_date' => '2020-06-01']);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['2019' => 3, '2020' => 2, '2018' => 1], $stats['distributions']['year']);
    }

    public function test_cd_library_reports_artist_and_year_distributions_but_no_genre(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Albums', 'media_type' => 'cd', 'owner_id' => $user->id]);

        MediaCd::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '2000000000001',
            'artist' => 'Band X', 'release_date' => '2019-01-01',
        ]);
        MediaCd::query()->create([
            'library_id' => $library->id, 'title' => 'B', 'ean' => '2000000000002',
            'artist' => 'Band X', 'release_date' => '2019-06-01',
        ]);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['Band X' => 2], $stats['distributions']['artist']);
        $this->assertSame(['2019' => 2], $stats['distributions']['year']);
        $this->assertArrayNotHasKey('genre', $stats['distributions']);
    }

    public function test_dvd_bluray_library_splits_the_comma_separated_languages_column(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $user->id]);

        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '3000000000001',
            'director' => 'X', 'languages' => 'Deutsch, Englisch', 'production_year' => 2020,
        ]);
        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'B', 'ean' => '3000000000002',
            'director' => 'Y', 'languages' => 'Englisch', 'production_year' => 2020,
        ]);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['Englisch' => 2, 'Deutsch' => 1], $stats['distributions']['language']);
        $this->assertSame(['X' => 1, 'Y' => 1], $stats['distributions']['director']);
        $this->assertSame(['2020' => 2], $stats['distributions']['year']);
    }

    /** GitHub issue #206: same count-not-chronological sort as book's own `year` distribution, here via `production_year` (integerYearDistribution()) instead of `release_date` (dateYearDistribution()). */
    public function test_dvd_bluray_library_sorts_the_year_distribution_by_count_not_chronologically(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $user->id]);

        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '3000000000013', 'production_year' => 2015]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '3000000000014', 'production_year' => 2016]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'C', 'ean' => '3000000000015', 'production_year' => 2016]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'D', 'ean' => '3000000000016', 'production_year' => 2016]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'E', 'ean' => '3000000000017', 'production_year' => 2017]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'F', 'ean' => '3000000000018', 'production_year' => 2017]);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['2016' => 3, '2017' => 2, '2015' => 1], $stats['distributions']['year']);
    }

    /** GitHub issue #205: individual language values can carry a trailing "Tonart" (audio format) annotation — parenthetical or colon-suffixed — which must be stripped before counting, so two different audio tracks for the same language are counted as one language, not two. */
    public function test_dvd_bluray_library_strips_tonart_annotations_from_the_languages_distribution(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $user->id]);

        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '3000000000009',
            'languages' => 'Deutsch (DD 5.1), Englisch (DTS-HD MA)', 'production_year' => 2020,
        ]);
        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'B', 'ean' => '3000000000010',
            'languages' => 'Deutsch: DD 2.0', 'production_year' => 2020,
        ]);
        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'C', 'ean' => '3000000000011',
            'languages' => 'Englisch', 'production_year' => 2020,
        ]);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['Deutsch' => 2, 'Englisch' => 2], $stats['distributions']['language']);
    }

    /** GitHub issue #205: the languages-only Tonart stripping must not leak into genre/medium, which use the same shared multiValueDistribution() helper but never pass a token transform. */
    public function test_genre_and_medium_distributions_keep_parenthetical_content_untouched(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $user->id]);
        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '3000000000012',
            'genre' => "Action (Director's Cut)", 'medium' => 'DVD (Region 2)',
        ]);

        $stats = $this->statsFor($library->id);

        $this->assertSame(["Action (Director's Cut)" => 1], $stats['distributions']['genre']);
        $this->assertSame(['DVD (Region 2)' => 1], $stats['distributions']['medium']);
    }

    /** GitHub issue #188: `director` (co-directors) and `cast` (ensemble casts) can each hold a comma-separated list too, same as `languages` — each name must be counted on its own, not the whole combination as one category. */
    public function test_dvd_bluray_library_splits_the_comma_separated_director_and_cast_columns(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $user->id]);

        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '3000000000003',
            'director' => 'Anthony Russo, Joe Russo', 'cast' => 'Robert Downey Jr., Chris Evans',
        ]);
        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'B', 'ean' => '3000000000004',
            'director' => 'Joe Russo', 'cast' => 'Chris Evans, Scarlett Johansson',
        ]);
        // No director/cast at all — must not appear in either distribution.
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'C', 'ean' => '3000000000005']);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['Joe Russo' => 2, 'Anthony Russo' => 1], $stats['distributions']['director']);
        $this->assertSame(['Chris Evans' => 2, 'Robert Downey Jr.' => 1, 'Scarlett Johansson' => 1], $stats['distributions']['cast']);
    }

    /** GitHub issue #204: `genre`/`medium` are now reported for DVD/Blu-ray too, split on comma the same way as `director`/`cast`/`language` (a combo pack's "DVD, Blu-ray"; a film tagged both "Action" and "Thriller"). */
    public function test_dvd_bluray_library_reports_genre_and_medium_distributions_split_on_comma(): void
    {
        $user = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $user->id]);

        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'A', 'ean' => '3000000000006',
            'genre' => 'Action, Thriller', 'medium' => 'DVD, Blu-ray',
        ]);
        MediaDvdBluray::query()->create([
            'library_id' => $library->id, 'title' => 'B', 'ean' => '3000000000007',
            'genre' => 'Action', 'medium' => 'DVD',
        ]);
        // No genre/medium at all — must not appear in either distribution.
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'C', 'ean' => '3000000000008']);

        $stats = $this->statsFor($library->id);

        $this->assertSame(['Action' => 2, 'Thriller' => 1], $stats['distributions']['genre']);
        $this->assertSame(['DVD' => 2, 'Blu-ray' => 1], $stats['distributions']['medium']);
    }

    public function test_distributions_respect_library_visibility(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '4000000000001', 'genre' => 'Sci-Fi']);

        // A different, unrelated user — not the owner, not an admin, no share.
        $this->actingAs(User::factory()->create(['level' => 'user', 'is_active' => true]));

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertNull(collect($response->json())->firstWhere('library_id', $library->id));
    }
}
