<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation's rooms now live on its lines (reservation_rooms), so the
 * single room column goes. Rolling back restores it from each
 * reservation's first live line that has a room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignUuid('room_id')->nullable()->after('guest_id')->constrained()->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE reservations SET room_id = (
                SELECT reservation_rooms.room_id FROM reservation_rooms
                WHERE reservation_rooms.reservation_id = reservations.id
                  AND reservation_rooms.room_id IS NOT NULL
                  AND reservation_rooms.status <> 'cancelled'
                  AND reservation_rooms.deleted_at IS NULL
                ORDER BY reservation_rooms.created_at, reservation_rooms.id
                LIMIT 1
            )
        SQL);
    }
};
