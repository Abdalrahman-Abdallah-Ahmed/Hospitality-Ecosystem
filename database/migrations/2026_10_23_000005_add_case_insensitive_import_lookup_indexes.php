<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reservation import matches reservation codes and room numbers
 * ignoring case, as legacy exports write them inconsistently. These indexes
 * keep those lookups from scanning the hotel's whole table, once per
 * imported reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX reservations_hotel_lower_reservation_id_index ON reservations (hotel_id, lower(reservation_id))');
        DB::statement('CREATE INDEX rooms_hotel_lower_room_number_index ON rooms (hotel_id, lower(room_number))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reservations_hotel_lower_reservation_id_index');
        DB::statement('DROP INDEX IF EXISTS rooms_hotel_lower_room_number_index');
    }
};
