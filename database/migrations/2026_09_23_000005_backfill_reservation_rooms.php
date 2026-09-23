<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives every existing reservation one room line from its single room:
 * that room and its type. A reservation with no usable room (none, or one
 * belonging to another hotel) gets the hotel's inactive "Unspecified
 * (migrated)" type, created only for hotels that need it.
 *
 * Soft-deleted reservations are included, so restoring one later still
 * finds its line. Only reservations with no line yet are touched, so a
 * re-run adds nothing. Room status is never written: occupancy derived
 * from one line per reservation equals what one room per reservation gave.
 *
 * Tables are written directly, not through models, so tenant scopes and
 * audit events stay out of a one-off data move.
 */
return new class extends Migration
{
    public const PLACEHOLDER_NAME = 'Unspecified (migrated)';

    public function up(): void
    {
        /** @var array<string, string> $placeholders hotel id => room type id */
        $placeholders = [];

        /** @var array<string, int> $placeholderCounts hotel id => reservations given the placeholder */
        $placeholderCounts = [];

        DB::table('reservations')
            ->leftJoin('rooms', function ($join) {
                $join->on('rooms.id', '=', 'reservations.room_id')
                    ->whereColumn('rooms.hotel_id', 'reservations.hotel_id');
            })
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('reservation_rooms')
                ->whereColumn('reservation_rooms.reservation_id', 'reservations.id'))
            ->select([
                'reservations.id',
                'reservations.hotel_id',
                'reservations.status',
                'rooms.id as room_id',
                'rooms.room_type_id',
            ])
            ->chunkById(500, function ($reservations) use (&$placeholders, &$placeholderCounts) {
                $now = now();
                $rows = [];

                foreach ($reservations as $reservation) {
                    $roomTypeId = $reservation->room_type_id;

                    if (! $roomTypeId) {
                        $roomTypeId = $placeholders[$reservation->hotel_id] ??= $this->placeholderType($reservation->hotel_id);
                        $placeholderCounts[$reservation->hotel_id] = ($placeholderCounts[$reservation->hotel_id] ?? 0) + 1;
                    }

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'hotel_id' => $reservation->hotel_id,
                        'reservation_id' => $reservation->id,
                        'room_type_id' => $roomTypeId,
                        'room_id' => $reservation->room_type_id ? $reservation->room_id : null,
                        'status' => $reservation->status === 'cancelled' ? 'cancelled' : 'reserved',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('reservation_rooms')->insert($rows);
            }, 'reservations.id', 'id');

        foreach ($placeholderCounts as $hotelId => $count) {
            $message = "Reservation rooms backfill: hotel {$hotelId} has {$count} reservation(s) without a usable room, filed under \"".self::PLACEHOLDER_NAME.'".';

            Log::info($message);

            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                echo $message.PHP_EOL;
            }
        }
    }

    /**
     * The migration only adds lines; dropping the reservation_rooms table
     * (the previous migration's down()) is what removes them.
     */
    public function down(): void {}

    private function placeholderType(string $hotelId): string
    {
        $existing = DB::table('room_types')
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower(self::PLACEHOLDER_NAME)])
            ->value('id');

        if ($existing) {
            return $existing;
        }

        $id = (string) Str::uuid();

        DB::table('room_types')->insert([
            'id' => $id,
            'hotel_id' => $hotelId,
            'name' => self::PLACEHOLDER_NAME,
            'description' => 'Created by the reservation-rooms migration for reservations without a room.',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => 0,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
