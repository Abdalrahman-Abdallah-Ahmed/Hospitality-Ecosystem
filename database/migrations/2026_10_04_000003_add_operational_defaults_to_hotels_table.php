<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a hotel's operational defaults (SPEC-004, D7): the inspection
 * category, the Maintenance team and its category, and whether cleaned rooms
 * must be inspected before they count as ready (SPEC-030). Read by id, never
 * by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignUuid('inspection_task_category_id')->nullable()->constrained('task_categories')->nullOnDelete();
            $table->foreignUuid('maintenance_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignUuid('maintenance_task_category_id')->nullable()->constrained('task_categories')->nullOnDelete();
            $table->boolean('inspection_required')->default(false);
            $table->timestampTz('inspection_required_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_task_category_id');
            $table->dropConstrainedForeignId('maintenance_team_id');
            $table->dropConstrainedForeignId('inspection_task_category_id');
            $table->dropColumn(['inspection_required', 'inspection_required_since']);
        });
    }
};
