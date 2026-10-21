<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("INSERT INTO reservation_guests (id, hotel_id, reservation_id, guest_id, is_primary, created_at, updated_at)\n            SELECT gen_random_uuid(), reservations.hotel_id, reservations.id, reservations.guest_id, true, NOW(), NOW()\n            FROM reservations\n            WHERE reservations.guest_id IS NOT NULL\n              AND NOT EXISTS (SELECT 1 FROM reservation_guests WHERE reservation_guests.reservation_id = reservations.id AND reservation_guests.deleted_at IS NULL)");
    }

    public function down(): void
    {
        // The backfilled rows are indistinguishable from later application
        // writes; leave them intact if only this migration is rolled back.
    }
};
