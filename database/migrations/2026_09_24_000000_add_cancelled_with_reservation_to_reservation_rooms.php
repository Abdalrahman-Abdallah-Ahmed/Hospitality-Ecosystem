<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the lines that were cancelled because their whole reservation was,
 * as opposed to lines staff removed one by one, so bringing the
 * reservation back restores exactly the rooms it had.
 *
 * Existing cancelled lines of cancelled reservations were cancelled with
 * them (the backfill wrote them that way), so they are marked too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->boolean('cancelled_with_reservation')->default(false)->after('status');
        });

        DB::table('reservation_rooms')
            ->where('status', 'cancelled')
            ->whereIn('reservation_id', DB::table('reservations')->where('status', 'cancelled')->select('id'))
            ->update(['cancelled_with_reservation' => true]);
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->dropColumn('cancelled_with_reservation');
        });
    }
};
