<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Dev/test-only dummy user — must NOT run in production, since it
        // creates a login (test@example.com / "password", Laravel's
        // well-known default factory password) that anyone could use to
        // sign in. Guarded so a production `php artisan db:seed` can't
        // accidentally create it.
        if (!app()->environment('production')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // PlatformSettingSeeder and AdminSeeder previously existed as
        // standalone seeder classes but were never actually invoked from
        // here — `php artisan db:seed` (or `migrate --seed`) ran this
        // class only, so neither ever fired. That's why platform_settings
        // rows like storage_grace_period_days never existed in the DB:
        // AdminController::getSettings() only returns rows that already
        // exist (PlatformSetting::orderBy('id')->get()), so a setting
        // with no row simply never appears in the admin Settings tab,
        // however correct the frontend/model code referencing it is.
        $this->call([
            PlatformSettingSeeder::class,
            AdminSeeder::class,
        ]);
    }
}