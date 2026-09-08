<?php

namespace App\Jobs;

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\StayStatus;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Marks every slept-in room dirty at the top of the day.
 *
 * A room that had a guest in it overnight needs servicing, whether or not
 * they are checking out today, so housekeeping starts each morning with an
 * accurate worklist instead of yesterday's leftovers.
 *
 * Two rules shape what it touches:
 *
 * - Only rooms with an in-house stay. An expected stay has not arrived and a
 *   departed one was already serviced on checkout, so neither dirties a room.
 * - Blocked rooms are left alone. Blocked means out of order — a fault, a leak,
 *   an unfinished repair — and downgrading that to "dirty" would erase the
 *   fault and put the room back in the assignable pool.
 *
 * Runs across every hotel: the scheduler has no tenant context, so the hotel
 * scope is dropped explicitly rather than left to depend on the caller.
 *
 * Idempotent — it sets a status rather than toggling one, so a re-run costs
 * nothing.
 */
class MakeRoomDirtyOvernightJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function handle(): void
    {
        $sleptInRoomIds = Stay::withoutGlobalScope('hotel')
            ->whereNotNull('room_id')
            ->where('status', StayStatus::IN_HOUSE)
            ->select('room_id');

        Room::withoutGlobalScope('hotel')
            ->whereIn('id', $sleptInRoomIds)
            ->where('housekeeping_status', '!=', HousekeepingStatusesEnum::BLOCKED)
            ->update(['housekeeping_status' => HousekeepingStatusesEnum::DIRTY]);
    }
}
