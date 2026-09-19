<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Written only by GuestContactService, never by the API. The stay columns
     * exist because a guest row is reused across stays at the same hotel: a
     * guest-level "last contacted" cannot say whether they messaged during
     * one particular stay once they message again during a later one.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            // When this person first and last messaged the hotel, on any stay.
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            $table->index(['hotel_id', 'last_contacted_at']);
        });

        Schema::table('stays', function (Blueprint $table) {
            // Inbound messages attributed to THIS stay, using the same rule
            // SenderRecognitionService uses to pick the reservation live.
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            // Serves the reception list: in-house, never contacted.
            $table->index(['hotel_id', 'status', 'first_contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'status', 'first_contacted_at']);
            $table->dropColumn(['first_contacted_at', 'last_contacted_at']);
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'last_contacted_at']);
            $table->dropColumn(['first_contacted_at', 'last_contacted_at']);
        });
    }
};
