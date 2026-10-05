<?php

use App\Models\Hotel;
use App\Support\Housekeeping\HotelOperationalDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives every existing hotel its default teams and categories (SPEC-004,
 * FR-040–042), then marks its existing open check-out cleaning tasks as
 * cleaning tasks, so they move their rooms like new ones (SPEC-030).
 *
 * One open cleaning task per room is a unique index, so where a room already
 * has several, only the newest is classified; the others stay ordinary tasks
 * and are reported for staff to close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Hotel::withoutGlobalScopes()->withTrashed()->orderBy('id')
            ->chunkById(100, function ($hotels): void {
                foreach ($hotels as $hotel) {
                    HotelOperationalDefaults::ensure($hotel);
                }
            });

        $open = DB::table('tasks')
            ->join('hotels', 'hotels.id', '=', 'tasks.hotel_id')
            ->whereColumn('tasks.task_category_id', 'hotels.cleaning_task_category_id')
            ->whereNotNull('tasks.room_id')
            ->whereNull('tasks.deleted_at')
            ->whereIn('tasks.status', ['pending', 'in_progress'])
            ->orderByDesc('tasks.created_at')
            ->get(['tasks.id', 'tasks.room_id', 'tasks.hotel_id']);

        foreach ($open->groupBy('room_id') as $roomId => $tasks) {
            DB::table('tasks')->where('id', $tasks->first()->id)->update([
                'housekeeping_kind' => 'cleaning',
                'cleaning_reason' => 'check_out',
            ]);

            if ($tasks->count() > 1) {
                $this->reportDuplicates($roomId, $tasks->first()->hotel_id, $tasks->skip(1)->pluck('id')->all());
            }
        }
    }

    public function down(): void
    {
        // The teams and categories are ordinary records admins may already
        // be using; they are left in place.
    }

    /**
     * @param  list<string>  $taskIds
     */
    private function reportDuplicates(string $roomId, string $hotelId, array $taskIds): void
    {
        Log::warning("Room {$roomId} has more than one open cleaning task; only the newest drives the room.", ['tasks' => $taskIds]);

        DB::table('event_log')->insert([
            'id' => (string) Str::uuid(),
            'hotel_id' => $hotelId,
            'event_type' => 'room.status_migration_conflict',
            'subject_type' => 'App\\Models\\Room',
            'subject_id' => $roomId,
            'actor_kind' => 'system',
            'changes' => json_encode(['cause' => ['to' => 'migration'], 'unclassified_task_ids' => ['to' => $taskIds]]),
            'evidence_level' => 'L1',
            'reason' => 'More than one open cleaning task; the older ones are ordinary tasks now.',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
