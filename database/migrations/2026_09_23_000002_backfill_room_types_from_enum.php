<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $combinations = DB::table('rooms')
            ->distinct()
            ->whereNotNull('room_type')
            ->select('hotel_id', 'room_type')
            ->get();

        $roomTypes = [];
        foreach ($combinations as $combo) {
            $roomTypes[] = [
                'id' => Str::uuid(),
                'hotel_id' => $combo->hotel_id,
                'name' => ucfirst($combo->room_type),
                'description' => "Auto-created from {$combo->room_type}",
                'max_occupancy' => 2,
                'adult_capacity' => 1,
                'child_capacity' => 1,
                'bed_configuration' => null,
                'amenities' => null,
                'base_price' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($roomTypes)) {
            DB::table('room_types')->insert($roomTypes);
        }
    }

    public function down(): void
    {
        DB::table('room_types')
            ->where('description', 'like', 'Auto-created from%')
            ->delete();
    }
};
