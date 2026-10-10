<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The guest asked for no proactive messages and no unsolicited offers.
     * Written only by GuestContactPreferenceService.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('proactive_opted_out_at')->nullable();
            $table->string('proactive_opt_out_source')->nullable();   // guest_message | staff
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['proactive_opted_out_at', 'proactive_opt_out_source']);
        });
    }
};
