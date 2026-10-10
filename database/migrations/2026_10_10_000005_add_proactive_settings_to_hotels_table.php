<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proactive messaging settings, read through App\Support\Proactive\ProactiveSettings.
     * Null means every default, which is off.
     */
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('proactive_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('proactive_settings');
        });
    }
};
