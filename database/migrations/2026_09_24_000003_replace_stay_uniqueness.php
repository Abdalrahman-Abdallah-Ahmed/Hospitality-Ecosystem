<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One stay per reservation line (D3; the backfill dropped the per-reservation
 * index), and at most one in-house stay per room, so no code path can put two
 * guests in one room.
 *
 * The second index cannot be created over data that already breaks it, so the
 * migration checks first and stops with the conflicting rooms listed: they
 * must be fixed by hand, not silently merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conflicts = DB::table('stays')
            ->where('status', 'in_house')
            ->whereNull('deleted_at')
            ->whereNotNull('room_id')
            ->groupBy('room_id')
            ->havingRaw('count(*) > 1')
            ->selectRaw("room_id, string_agg(id::text, ', ') as stay_ids")
            ->get();

        if ($conflicts->isNotEmpty()) {
            $list = $conflicts->map(fn ($row) => "room {$row->room_id}: stays {$row->stay_ids}")->implode('; ');

            throw new RuntimeException("Rooms with more than one in-house stay must be fixed before this migration: {$list}.");
        }

        DB::statement(
            'CREATE UNIQUE INDEX stays_reservation_room_id_unique ON stays (reservation_room_id) '
            .'WHERE deleted_at IS NULL AND reservation_room_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX stays_one_in_house_per_room ON stays (room_id) '
            ."WHERE status = 'in_house' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stays_one_in_house_per_room');
        DB::statement('DROP INDEX IF EXISTS stays_reservation_room_id_unique');
    }
};
