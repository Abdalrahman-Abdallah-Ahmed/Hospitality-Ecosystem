<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $table->dropForeign(['room_type_id']);
            $table->dropColumn('room_type_id');
            $table->string('room_type')->nullable();
        });

        $rooms = DB::table('rooms')
            ->whereNotNull('room_type_id')
            ->join('room_types', 'rooms.room_type_id', '=', 'room_types.id')
            ->select('rooms.id', 'room_types.name')
            ->get();

        foreach ($rooms as $room) {
            DB::table('rooms')
                ->where('id', $room->id)
                ->update(['room_type' => strtolower($room->name)]);
        }
    }
};
