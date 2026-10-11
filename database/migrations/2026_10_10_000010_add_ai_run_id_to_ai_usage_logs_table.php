<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which AI run a provider call belonged to, so a run's usage (and, for
     * super admins, its cost) is summed from the measured rows (SPEC-083).
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('ai_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropIndex(['ai_run_id']);
            $table->dropColumn('ai_run_id');
        });
    }
};
