<?php

namespace Database\Seeders;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\Library;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Runs on first start (briefing 4.1 + 5.): creates the initial admin
 * account from MEDINV_ADMINUSER/MEDINV_ADMINPASS, and — if
 * MEDINV_SEED_SAMPLE_LIBRARY is truthy — an example library with test data
 * so the application can be tried out immediately (5.).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // MetadataProviderRegistry::defaultProviders() only lists which classes
        // *could* run — MetadataImportService actually only calls providers with
        // a corresponding, enabled metadata_plugins row (enabledProvidersFor()).
        // Nothing else in the app ever created those rows, so a fresh install had
        // zero enabled providers and every capture/search silently hit "no_match"
        // regardless of what's implemented — found while wiring up UpcMdbProvider.
        // firstOrCreate()-based like syncToDatabase() itself, so — same as the
        // admin account below — this also self-heals an existing deployment on
        // its next restart (db:seed --force runs on every container start).
        app(MetadataProviderRegistry::class)->syncToDatabase();

        $adminEmail = env('MEDINV_ADMINUSER');
        $adminPassword = env('MEDINV_ADMINPASS');

        if (! $adminEmail || ! $adminPassword) {
            $this->command?->warn('MEDINV_ADMINUSER / MEDINV_ADMINPASS not set — skipping initial admin creation.');

            return;
        }

        // is_protected: this account can never be deleted via the admin UI
        // (UserController::destroy) — every install needs one account
        // nobody can accidentally lock everyone out of by removing.
        $admin = User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Administrator',
                'password' => $adminPassword,
                'level' => 'admin',
                'is_active' => true,
                'is_protected' => true,
            ],
        );

        // Upgrade path: firstOrCreate() only sets is_protected on first
        // creation, so a deployment seeded before this flag existed needs
        // it backfilled explicitly (db:seed re-runs on every container
        // start, so this self-heals on the next restart with no extra step).
        if (! $admin->is_protected) {
            $admin->update(['is_protected' => true]);
        }

        if (env('MEDINV_SEED_SAMPLE_LIBRARY', false)) {
            $this->call(SampleLibrarySeeder::class);
        }
    }
}
