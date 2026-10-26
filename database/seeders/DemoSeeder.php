<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The sample hotel, owned by the seeded super admin, with its activities,
 * knowledge, a guest and a reservation (spec 017 FR-030). Never part of a
 * production install unless SEED_DEMO=true.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(HospitalitySeeder::class);
        $this->call(KnowledgeBaseSeeder::class);
        $this->call(GuestReservationSeeder::class);
    }
}
