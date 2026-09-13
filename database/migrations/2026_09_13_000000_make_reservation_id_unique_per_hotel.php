<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reservation code is issued by the hotel or its channel manager, so two
     * properties can legitimately both hold "RES-1001". Unique per hotel, not
     * across the whole platform — where the second hotel to use a code could
     * not store it at all.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['reservation_id']);
            $table->unique(['hotel_id', 'reservation_id']);
        });
    }

    /**
     * Fails if two hotels have since used the same code — restoring the global
     * rule would have to discard one of them, which a rollback must not decide.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'reservation_id']);
            $table->unique('reservation_id');
        });
    }
};
