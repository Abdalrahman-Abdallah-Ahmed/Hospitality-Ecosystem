<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a booking happens, in the hotel's own terms (SPEC-041, research R3/R9):
 *
 * - `scheduled_date` / `scheduled_time` are the hotel-local start, and
 *   `last_date` the last day a multi-day activity covers, fixed when the
 *   booking is saved. A date's booked load is then one range test on the
 *   index below, and a later change to the activity's duration does not move
 *   existing bookings.
 * - `reservation_id` lets a booking belong to a guest before they arrive,
 *   when there is no stay yet.
 * - `notes` are staff notes, editable while the booking is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUuid('reservation_id')->nullable()->after('stay_id')->constrained()->nullOnDelete();
            $table->date('scheduled_date')->nullable()->after('scheduled_for');
            $table->time('scheduled_time')->nullable()->after('scheduled_date');
            $table->date('last_date')->nullable()->after('scheduled_time');
            $table->text('notes')->nullable();

            $table->index('reservation_id');
            $table->index(['activity_id', 'scheduled_date', 'last_date']);
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_last_date_check CHECK (last_date IS NULL OR scheduled_date IS NULL OR last_date >= scheduled_date)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_last_date_check');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['activity_id', 'scheduled_date', 'last_date']);
            $table->dropIndex(['reservation_id']);
            $table->dropConstrainedForeignId('reservation_id');
            $table->dropColumn(['scheduled_date', 'scheduled_time', 'last_date', 'notes']);
        });
    }
};
