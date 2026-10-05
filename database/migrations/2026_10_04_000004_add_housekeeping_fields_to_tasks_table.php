<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a task means to housekeeping (SPEC-030/035):
 *
 * - `housekeeping_kind` marks the tasks that move a room (cleaning or
 *   inspection). The partial index allows one open task of each kind per
 *   room (FR-007).
 * - `cleaning_reason`, the inspection result and note, the issue report's
 *   source task, and when the task was completed (an issue can be reported on
 *   a housekeeping task on the day it was completed).
 *
 * Plus the receipts that keep task notices to one per person per assignment,
 * and the record that a hotel's start-of-day ran for a date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('housekeeping_kind')->nullable();
            $table->string('cleaning_reason')->nullable();
            $table->string('inspection_result')->nullable();
            $table->text('inspection_note')->nullable();
            $table->foreignUuid('source_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_housekeeping_kind_check CHECK (housekeeping_kind IN ('cleaning', 'inspection'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_cleaning_reason_check CHECK (cleaning_reason IN ('check_out', 'stay_over', 're_clean', 'return_to_service', 'manual'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_inspection_result_check CHECK (inspection_result IN ('pass', 'fail'))");
        DB::statement(
            'CREATE UNIQUE INDEX tasks_one_open_housekeeping_kind_per_room ON tasks (room_id, housekeeping_kind) '
            ."WHERE housekeeping_kind IS NOT NULL AND status IN ('pending', 'in_progress') AND deleted_at IS NULL"
        );

        Schema::create('task_notification_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('notified_at');
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('housekeeping_day_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('rooms_dirtied')->default(0);
            $table->unsignedInteger('tasks_created')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['hotel_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_day_runs');
        Schema::dropIfExists('task_notification_receipts');
        DB::statement('DROP INDEX IF EXISTS tasks_one_open_housekeeping_kind_per_room');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_task_id');
            $table->dropColumn(['housekeeping_kind', 'cleaning_reason', 'inspection_result', 'inspection_note', 'completed_at']);
        });
    }
};
