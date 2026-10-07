<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guest's request to cancel a booking is a task (SPEC-043, research R12):
 *
 * - `booking_id` links it to the booking.
 * - `resolution`, its note, who decided and when record how staff answered,
 *   so the guest can be told (Phase 7).
 *
 * The partial index allows one open request per booking (FR-024), whatever
 * path creates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('resolution')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignUuid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            $table->index('booking_id');
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_resolution_check CHECK (resolution IN ('approved', 'declined'))");
        DB::statement(
            'CREATE UNIQUE INDEX tasks_one_open_cancellation_request_per_booking ON tasks (booking_id) '
            ."WHERE guest_signal = 'cancellation_request' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tasks_one_open_cancellation_request_per_booking');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_resolution_check');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['booking_id']);
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['resolution', 'resolution_note', 'resolved_at']);
        });
    }
};
