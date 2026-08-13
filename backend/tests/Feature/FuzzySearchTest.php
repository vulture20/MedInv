<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GitHub issue #9: `fuzzy=true` used to only relax case-sensitivity — a real
 * typo (e.g. "Frankenstien" instead of "Frankenstein") still found nothing.
 * This covers the actual typo-tolerant matching added to SearchService,
 * kept separate from SearchTest.php, which is scoped to two specific
 * historical bugs (the 422-on-"false" and the reserved-keyword-`cast` bug).
 */
class FuzzySearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'admin'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function createDvdBlurayLibrary(User $owner): Library
    {
        $library = Library::query()->create([
            'name' => 'Films',
            'media_type' => 'dvd_bluray',
            'owner_id' => $owner->id,
        ]);

        MediaDvdBluray::query()->create([
            'library_id' => $library->id,
            'title' => 'Frankenstein',
            'cast' => 'Boris Karloff',
            'director' => 'James Whale',
            'ean' => '1234567890123',
        ]);

        return $library;
    }

    public function test_a_typo_finds_a_match_with_fuzzy_true(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Frankenstien&fuzzy=true');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Frankenstein', $response->json('0.title'));
    }

    public function test_the_same_typo_finds_nothing_with_fuzzy_false(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Frankenstien&fuzzy=false');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_a_typo_in_the_cast_column_still_matches(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Karlof&fuzzy=true');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_a_typo_in_an_unshared_library_is_not_found(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->createDvdBlurayLibrary($owner);

        // A different, unrelated user — not the owner, not an admin, no share.
        $this->actingAsUser('user');

        $response = $this->getJson('/api/search?query=Frankenstien&fuzzy=true');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_too_many_differences_still_finds_nothing(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Automobile&fuzzy=true');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    /**
     * Self-skips unless actually run against a real Postgres connection
     * (the default/CI connection is sqlite, see phpunit.xml) — inert under
     * normal `php artisan test`, but provides real coverage for the
     * pg_trgm-accelerated branch when manually run with
     * MEDINV_DB_CONNECTION=pgsql against a live Postgres instance that has
     * had `php artisan migrate` applied.
     */
    public function test_postgres_trigram_index_is_actually_used(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Only meaningful against a real Postgres connection.');
        }

        $this->assertNotEmpty(
            DB::select("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"),
            'pg_trgm extension is not installed on this Postgres connection.',
        );

        $this->assertNotEmpty(
            DB::select("SELECT indexname FROM pg_indexes WHERE indexname LIKE '%media_dvd_blurays_title_trgm_idx'"),
            'Expected GIN trigram index on media_dvd_blurays.title was not created.',
        );

        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Frankenstien&fuzzy=true');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Frankenstein', $response->json('0.title'));
    }
}
