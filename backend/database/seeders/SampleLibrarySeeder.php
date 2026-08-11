<?php

namespace Database\Seeders;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Optional example data offered at first setup (briefing 5.), one sample
 * library per media type (Buch/CD/DVD-Blu-ray, briefing 6.) with at least
 * 10 entries each, so all three capture/search/statistics code paths have
 * something to show immediately rather than just books.
 */
class SampleLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('level', 'admin')->first();

        if (! $owner) {
            $this->command?->warn('No admin user found — skipping sample libraries.');

            return;
        }

        $this->seedBooks($owner);
        $this->seedCds($owner);
        $this->seedDvdBlurays($owner);
    }

    private function seedBooks(User $owner): void
    {
        $library = Library::query()->firstOrCreate(
            ['name' => 'Sample Library – Books', 'owner_id' => $owner->id],
            ['description' => 'Example book collection with test data.', 'media_type' => 'book', 'is_sample_library' => true],
        );

        $samples = [
            ['title' => 'Die Verwandlung', 'authors' => 'Franz Kafka', 'ean' => '9783150091000', 'isbn13' => '9783150091000', 'genre' => 'Novelle', 'language' => 'de', 'page_count' => 96, 'publisher' => 'Reclam', 'price' => 4.80],
            ['title' => '1984', 'authors' => 'George Orwell', 'ean' => '9780451524935', 'isbn13' => '9780451524935', 'genre' => 'Dystopie', 'language' => 'en', 'page_count' => 328, 'publisher' => 'Signet Classics', 'price' => 9.99],
            ['title' => 'Der Steppenwolf', 'authors' => 'Hermann Hesse', 'ean' => '9783518366803', 'isbn13' => '9783518366803', 'genre' => 'Roman', 'language' => 'de', 'page_count' => 288, 'publisher' => 'Suhrkamp', 'price' => 11.00],
            ['title' => 'Faust', 'authors' => 'Johann Wolfgang von Goethe', 'ean' => '9783150000019', 'isbn13' => '9783150000019', 'genre' => 'Drama', 'language' => 'de', 'page_count' => 364, 'publisher' => 'Reclam', 'price' => 6.20],
            ['title' => 'Pride and Prejudice', 'authors' => 'Jane Austen', 'ean' => '9780141439518', 'isbn13' => '9780141439518', 'genre' => 'Roman', 'language' => 'en', 'page_count' => 480, 'publisher' => 'Penguin Classics', 'price' => 8.50],
            ['title' => 'Moby-Dick', 'authors' => 'Herman Melville', 'ean' => '9780142437247', 'isbn13' => '9780142437247', 'genre' => 'Abenteuerroman', 'language' => 'en', 'page_count' => 720, 'publisher' => 'Penguin Classics', 'price' => 10.00],
            ['title' => 'Die Blechtrommel', 'authors' => 'Günter Grass', 'ean' => '9783423119004', 'isbn13' => '9783423119004', 'genre' => 'Roman', 'language' => 'de', 'page_count' => 780, 'publisher' => 'dtv', 'price' => 14.90],
            ['title' => 'Crime and Punishment', 'authors' => 'Fyodor Dostoevsky', 'ean' => '9780143107637', 'isbn13' => '9780143107637', 'genre' => 'Roman', 'language' => 'en', 'page_count' => 671, 'publisher' => 'Penguin Classics', 'price' => 12.00],
            ['title' => 'Der Prozess', 'authors' => 'Franz Kafka', 'ean' => '9783150182920', 'isbn13' => '9783150182920', 'genre' => 'Roman', 'language' => 'de', 'page_count' => 288, 'publisher' => 'Reclam', 'price' => 7.60],
            ['title' => 'Frankenstein', 'authors' => 'Mary Shelley', 'ean' => '9780141439471', 'isbn13' => '9780141439471', 'genre' => 'Horror', 'language' => 'en', 'page_count' => 280, 'publisher' => 'Penguin Classics', 'price' => 8.50],
        ];

        foreach ($samples as $sample) {
            MediaBook::query()->firstOrCreate(
                ['library_id' => $library->id, 'ean' => $sample['ean']],
                [...$sample, 'library_id' => $library->id],
            );
        }
    }

    private function seedCds(User $owner): void
    {
        $library = Library::query()->firstOrCreate(
            ['name' => 'Sample Library – CDs', 'owner_id' => $owner->id],
            ['description' => 'Example CD collection with test data.', 'media_type' => 'cd', 'is_sample_library' => true],
        );

        $samples = [
            ['title' => 'Abbey Road', 'artist' => 'The Beatles', 'ean' => '5099969944222', 'medium' => 'CD', 'disc_count' => 1, 'price' => 12.99],
            ['title' => 'Thriller', 'artist' => 'Michael Jackson', 'ean' => '5099750422324', 'medium' => 'CD', 'disc_count' => 1, 'price' => 11.49],
            ['title' => 'The Dark Side of the Moon', 'artist' => 'Pink Floyd', 'ean' => '5099902987939', 'medium' => 'CD', 'disc_count' => 1, 'price' => 13.99],
            ['title' => 'Back in Black', 'artist' => 'AC/DC', 'ean' => '5099751087729', 'medium' => 'CD', 'disc_count' => 1, 'price' => 10.99],
            ['title' => 'Rumours', 'artist' => 'Fleetwood Mac', 'ean' => '0081227951371', 'medium' => 'CD', 'disc_count' => 1, 'price' => 12.49],
            ['title' => 'Nevermind', 'artist' => 'Nirvana', 'ean' => '0720642442723', 'medium' => 'CD', 'disc_count' => 1, 'price' => 9.99],
            ['title' => 'Hotel California', 'artist' => 'Eagles', 'ean' => '0081227965859', 'medium' => 'CD', 'disc_count' => 1, 'price' => 11.99],
            ['title' => 'Kind of Blue', 'artist' => 'Miles Davis', 'ean' => '0074646452928', 'medium' => 'CD', 'disc_count' => 1, 'price' => 14.49],
            ['title' => '21', 'artist' => 'Adele', 'ean' => '0886978703922', 'medium' => 'CD', 'disc_count' => 1, 'price' => 10.49],
            ['title' => 'The Wall', 'artist' => 'Pink Floyd', 'ean' => '5099902988295', 'medium' => 'CD', 'disc_count' => 2, 'price' => 16.99],
        ];

        foreach ($samples as $sample) {
            MediaCd::query()->firstOrCreate(
                ['library_id' => $library->id, 'ean' => $sample['ean']],
                [...$sample, 'library_id' => $library->id],
            );
        }
    }

    private function seedDvdBlurays(User $owner): void
    {
        $library = Library::query()->firstOrCreate(
            ['name' => 'Sample Library – DVD/Blu-ray', 'owner_id' => $owner->id],
            ['description' => 'Example DVD/Blu-ray collection with test data.', 'media_type' => 'dvd_bluray', 'is_sample_library' => true],
        );

        $samples = [
            ['title' => 'Casablanca', 'director' => 'Michael Curtiz', 'medium' => 'DVD', 'disc_count' => 1, 'runtime_minutes' => 102, 'production_year' => 1942, 'price' => 9.99],
            ['title' => 'Metropolis', 'director' => 'Fritz Lang', 'medium' => 'Blu-ray', 'disc_count' => 1, 'runtime_minutes' => 153, 'production_year' => 1927, 'price' => 14.99],
            ['title' => 'Der Pate', 'director' => 'Francis Ford Coppola', 'medium' => 'Blu-ray', 'disc_count' => 1, 'runtime_minutes' => 175, 'production_year' => 1972, 'price' => 12.99],
            ['title' => '2001: A Space Odyssey', 'director' => 'Stanley Kubrick', 'medium' => 'Blu-ray', 'disc_count' => 1, 'runtime_minutes' => 149, 'production_year' => 1968, 'price' => 13.99],
            ['title' => 'Das Boot', 'director' => 'Wolfgang Petersen', 'medium' => 'DVD', 'disc_count' => 2, 'runtime_minutes' => 149, 'production_year' => 1981, 'price' => 11.99],
            ['title' => 'Pulp Fiction', 'director' => 'Quentin Tarantino', 'medium' => 'Blu-ray', 'disc_count' => 1, 'runtime_minutes' => 154, 'production_year' => 1994, 'price' => 10.99],
            ['title' => 'Lawrence of Arabia', 'director' => 'David Lean', 'medium' => 'Blu-ray', 'disc_count' => 1, 'runtime_minutes' => 218, 'production_year' => 1962, 'price' => 15.99],
            ['title' => 'Nosferatu', 'director' => 'F. W. Murnau', 'medium' => 'DVD', 'disc_count' => 1, 'runtime_minutes' => 94, 'production_year' => 1922, 'price' => 8.99],
            ['title' => 'Vertigo', 'director' => 'Alfred Hitchcock', 'medium' => 'Blu-ray', 'disc_count' => 1, 'runtime_minutes' => 128, 'production_year' => 1958, 'price' => 12.49],
            ['title' => 'Die zwölf Geschworenen', 'director' => 'Sidney Lumet', 'medium' => 'DVD', 'disc_count' => 1, 'runtime_minutes' => 96, 'production_year' => 1957, 'price' => 7.99],
        ];

        foreach ($samples as $i => $sample) {
            $ean = sprintf('4006381%06d', 300000 + $i);
            MediaDvdBluray::query()->firstOrCreate(
                ['library_id' => $library->id, 'ean' => $ean],
                [...$sample, 'ean' => $ean, 'library_id' => $library->id],
            );
        }
    }
}
