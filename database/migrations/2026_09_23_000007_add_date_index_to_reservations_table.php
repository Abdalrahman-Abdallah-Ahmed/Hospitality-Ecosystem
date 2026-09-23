<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Availability reads every reservation of a hotel overlapping a date range;
     * without this it scans the hotel's whole reservation history.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['hotel_id', 'arrival_date', 'departure_date'], 'reservations_hotel_dates_index');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_hotel_dates_index');
        });
    }
};
