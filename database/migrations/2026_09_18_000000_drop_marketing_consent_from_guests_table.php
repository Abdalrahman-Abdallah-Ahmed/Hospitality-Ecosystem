<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guests give consent when they make the reservation, so a per-guest flag
     * records nothing the hotel does not already know — and a column that
     * defaults to false reads as "did not consent" for almost every guest.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('marketing_consent');
        });
    }

    /**
     * Restores the column, not its values: every guest comes back as false.
     */
    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false);
        });
    }
};
