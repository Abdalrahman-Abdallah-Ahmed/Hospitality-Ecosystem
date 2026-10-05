<?php

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Models\EventLog;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| The room status split (SPEC-003, D5, FR-037–039): existing statuses are
| moved to the new values, and the database refuses the old ones.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * Re-runs the split over rows written with the old values, the way the
 * migration meets them on a live database.
 */
function rerunStatusSplit(callable $seedOldRows): void
{
    $migration = require database_path('migrations/2026_10_04_000002_split_room_statuses.php');
    $migration->down();
    $seedOldRows();
    $migration->up();
}

it('moves maintenance and blocked rooms to out of order and dirty, and keeps the rest', function () {
    [$hotel, $type, [$maintenance, $blocked, $plain]] = fdHotel(3);

    rerunStatusSplit(function () use ($maintenance, $blocked) {
        DB::table('rooms')->where('id', $maintenance->id)->update(['status' => 'maintenance']);
        DB::table('rooms')->where('id', $blocked->id)->update(['housekeeping_status' => 'blocked']);
    });

    foreach ([$maintenance, $blocked] as $room) {
        $room = Room::withoutGlobalScope('hotel')->find($room->id);
        expect($room->status)->toBe(RoomStatusesEnum::OUT_OF_ORDER)
            ->and($room->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY)
            ->and($room->out_of_order_reason)->toBe('Migrated from previous status');
    }

    expect(Room::withoutGlobalScope('hotel')->find($plain->id)->status)->toBe(RoomStatusesEnum::AVAILABLE);
});

it('keeps a blocked room with a guest in it occupied and reports it', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();

    rerunStatusSplit(fn () => DB::table('rooms')->where('id', $room->id)->update(['housekeeping_status' => 'blocked']));

    $room = Room::withoutGlobalScope('hotel')->find($room->id);
    expect($room->status)->toBe(RoomStatusesEnum::OCCUPIED)
        ->and($room->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY)
        ->and(EventLog::where('subject_id', $room->id)->where('event_type', 'room.status_migration_conflict')->count())->toBe(1);
});

it('refuses the old values in the database', function (string $column, string $value) {
    [$hotel, $type, [$room]] = fdHotel(1);

    expect(fn () => DB::table('rooms')->where('id', $room->id)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    ['status', 'maintenance'],
    ['housekeeping_status', 'blocked'],
]);
