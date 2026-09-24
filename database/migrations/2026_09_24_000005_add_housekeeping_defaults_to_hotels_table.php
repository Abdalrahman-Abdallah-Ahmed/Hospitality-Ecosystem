<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team and task category a check-out's cleaning task goes to (FR-013).
 * Chosen by id, never by name, so they work whatever language the hotel names
 * its teams in. SPEC-004 fills them for every hotel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignUuid('housekeeping_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignUuid('cleaning_task_category_id')->nullable()->constrained('task_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cleaning_task_category_id');
            $table->dropConstrainedForeignId('housekeeping_team_id');
        });
    }
};
