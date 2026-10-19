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
        // A sign-in everyone knows: for a developer's machine and the tests only.
        if (app()->environment(['local', 'testing'])) {
            User::updateOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password']
            );
        }

        $this->call([
            AdminSeeder::class,
            PlanSeeder::class,
            VoiceSeeder::class,
            HybridTtsSeeder::class,
            VoiceSampleSeeder::class,
        ]);
    }
}
