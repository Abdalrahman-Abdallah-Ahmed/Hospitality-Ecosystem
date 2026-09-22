<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->uuid('room_type_id')->nullable()->after('room_number');
        });

        $rooms = DB::table('rooms')
            ->whereNotNull('room_type')
            ->select('id', 'hotel_id', 'room_type')
            ->get();

        foreach ($rooms as $room) {
            $roomType = DB::table('room_types')
                ->where('hotel_id', $room->hotel_id)
                ->where('name', ucfirst($room->room_type))
                ->first();

            if ($roomType) {
                DB::table('rooms')
                    ->where('id', $room->id)
                    ->update(['room_type_id' => $roomType->id]);
            }
        }

        // Rooms that never had a type (or whose type did not match) go under a
        // per-hotel "Standard" type, so the column can become NOT NULL.
        $untypedHotelIds = DB::table('rooms')->whereNull('room_type_id')->distinct()->pluck('hotel_id');

        foreach ($untypedHotelIds as $hotelId) {
            $standard = DB::table('room_types')
                ->where('hotel_id', $hotelId)
                ->whereRaw('lower(name) = ?', ['standard'])
                ->value('id');

            if (! $standard) {
                $standard = (string) Str::uuid();

                DB::table('room_types')->insert([
                    'id' => $standard,
                    'hotel_id' => $hotelId,
                    'name' => 'Standard',
                    'description' => 'Created automatically; review capacity and price.',
                    'max_occupancy' => 2,
                    'adult_capacity' => 2,
                    'child_capacity' => 0,
                    'base_price' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('rooms')
                ->where('hotel_id', $hotelId)
                ->whereNull('room_type_id')
                ->update(['room_type_id' => $standard]);
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->uuid('room_type_id')->nullable(false)->change();
            $table->foreign('room_type_id')
                ->references('id')
                ->on('room_types')
                ->onDelete('cascade');
            $table->dropColumn('room_type');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('room_type')->nullable();
        });

        $rooms = DB::table('rooms')
            ->join('room_types', 'rooms.room_type_id', '=', 'room_types.id')
            ->select('rooms.id', 'room_types.name')
            ->get();

        foreach ($rooms as $room) {
            DB::table('rooms')
                ->where('id', $room->id)
                ->update(['room_type' => strtolower($room->name)]);
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
            $table->dropColumn('room_type_id');
        });
    }
};
