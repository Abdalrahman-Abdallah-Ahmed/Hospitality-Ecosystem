<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The room status split (SPEC-003, D5): room status becomes available /
 * occupied / out_of_order and housekeeping status dirty / cleaning / clean /
 * inspected. A `maintenance` room and a `blocked` housekeeping status both
 * meant "out of service", so they become out_of_order and dirty.
 *
 * A room in that state with a guest in it cannot be out of order (FR-017):
 * it becomes occupied and dirty instead, and is reported — in the log, on the
 * console and as a room.status_migration_conflict audit entry — for staff to
 * handle. Also adds `building` (D6) and the time of the last housekeeping
 * change, which readiness needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('building', 100)->nullable()->after('floor');
            $table->timestampTz('housekeeping_status_changed_at')->nullable();
        });

        DB::statement('UPDATE rooms SET housekeeping_status_changed_at = updated_at');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_status_check');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_housekeeping_status_check');

        $outOfService = DB::table('rooms')
            ->where(fn ($query) => $query->where('status', 'maintenance')->orWhere('housekeeping_status', 'blocked'))
            ->get(['id', 'hotel_id', 'room_number', 'status', 'housekeeping_status']);

        $occupied = DB::table('stays')
            ->where('status', 'in_house')
            ->whereNull('deleted_at')
            ->whereIn('room_id', $outOfService->pluck('id'))
            ->pluck('room_id')
            ->flip();

        $now = now();

        foreach ($outOfService as $room) {
            if ($occupied->has($room->id)) {
                DB::table('rooms')->where('id', $room->id)->update([
                    'status' => 'occupied',
                    'housekeeping_status' => 'dirty',
                    'housekeeping_status_changed_at' => $now,
                ]);
                $this->reportConflict($room);

                continue;
            }

            DB::table('rooms')->where('id', $room->id)->update([
                'status' => 'out_of_order',
                'housekeeping_status' => 'dirty',
                'housekeeping_status_changed_at' => $now,
                'out_of_order_reason' => 'Migrated from previous status',
                'out_of_order_since' => $now,
            ]);
        }

        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_status_check CHECK (status IN ('available', 'occupied', 'out_of_order'))");
        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_housekeeping_status_check CHECK (housekeeping_status IN ('dirty', 'cleaning', 'clean', 'inspected'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_status_check');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_housekeeping_status_check');

        DB::table('rooms')->where('status', 'out_of_order')->update(['status' => 'maintenance']);
        DB::table('rooms')->where('housekeeping_status', 'cleaning')->update(['housekeeping_status' => 'dirty']);
        DB::table('rooms')->where('housekeeping_status', 'inspected')->update(['housekeeping_status' => 'clean']);
        DB::table('rooms')->update(['out_of_order_reason' => null, 'out_of_order_since' => null]);

        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_status_check CHECK (status IN ('available', 'occupied', 'maintenance'))");
        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_housekeeping_status_check CHECK (housekeeping_status IN ('clean', 'dirty', 'blocked'))");

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['building', 'housekeeping_status_changed_at']);
        });
    }

    private function reportConflict(object $room): void
    {
        $message = "Room {$room->room_number} ({$room->id}) was {$room->status}/{$room->housekeeping_status} "
            .'with an in-house guest; it is now occupied and dirty instead of out of order.';

        Log::warning('Room status migration conflict: '.$message);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo '  '.$message.PHP_EOL;
        }

        DB::table('event_log')->insert([
            'id' => (string) Str::uuid(),
            'hotel_id' => $room->hotel_id,
            'event_type' => 'room.status_migration_conflict',
            'subject_type' => 'App\\Models\\Room',
            'subject_id' => $room->id,
            'actor_kind' => 'system',
            'changes' => json_encode([
                'status' => ['from' => $room->status, 'to' => 'occupied'],
                'housekeeping_status' => ['from' => $room->housekeeping_status, 'to' => 'dirty'],
                'cause' => ['to' => 'migration'],
            ]),
            'evidence_level' => 'L1',
            'reason' => 'Out of service with an in-house guest; could not be set out of order.',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
