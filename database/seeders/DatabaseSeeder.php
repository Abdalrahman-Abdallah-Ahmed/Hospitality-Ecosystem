<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database: the platform always, the demo hotel
     * only in local and testing or when SEED_DEMO=true (spec 017 FR-030).
     */
    public function run(): void
    {
        $this->call(AiModelPriceSeeder::class);
        $this->call(PlatformSeeder::class);

        if (app()->environment('local', 'testing') || config('seeding.demo')) {
            $this->call(DemoSeeder::class);
        }
    }
}
