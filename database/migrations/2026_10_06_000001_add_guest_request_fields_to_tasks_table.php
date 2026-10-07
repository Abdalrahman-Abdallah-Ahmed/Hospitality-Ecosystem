<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest requests and the notice that closes their loop (SPEC-007):
 *
 * - `guest_notice_*` record whether the guest was told how their request
 *   ended, by which channel, or why not. Written only by
 *   SendGuestRequestNoticeJob; null means not processed yet.
 * - One open escalation per guest per hotel, and one open room-change request
 *   per stay (or per reservation before arrival), whatever path creates them.
 *
 * Existing data: older duplicate open escalations are folded into the newest
 * one, and guest tasks already closed are marked `skipped`, so nothing that
 * finished before this shipped is ever announced to a guest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('guest_notice_status')->nullable();
            $table->string('guest_notice_channel')->nullable();
            $table->string('guest_notice_reason')->nullable();
            $table->timestampTz('guest_notice_at')->nullable();
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_guest_notice_status_check CHECK (guest_notice_status IN ('pending', 'sent', 'skipped', 'failed'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_guest_notice_channel_check CHECK (guest_notice_channel IN ('whatsapp', 'email'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_guest_notice_reason_check CHECK (guest_notice_reason IN ('cancelled', 'no_contact', 'send_failed'))");

        $this->foldDuplicateEscalations();

        DB::table('tasks')
            ->whereIn('status', ['completed', 'cancelled'])
            ->whereIn('guest_signal', ['service_request', 'cancellation_request'])
            ->whereNull('guest_notice_status')
            ->update(['guest_notice_status' => 'skipped', 'guest_notice_at' => now()]);

        DB::statement(
            'CREATE UNIQUE INDEX tasks_one_open_escalation_per_guest ON tasks (hotel_id, guest_id) '
            ."WHERE guest_signal = 'escalation' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX tasks_one_open_room_change_per_stay ON tasks (COALESCE(stay_id, reservation_id)) '
            ."WHERE guest_signal = 'room_change_request' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tasks_one_open_room_change_per_stay');
        DB::statement('DROP INDEX IF EXISTS tasks_one_open_escalation_per_guest');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_guest_notice_reason_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_guest_notice_channel_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_guest_notice_status_check');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['guest_notice_status', 'guest_notice_channel', 'guest_notice_reason', 'guest_notice_at']);
        });
    }

    /**
     * Keep the newest open escalation per guest and hotel; append the older
     * ones' descriptions to it and close them quietly (no notice: escalations
     * never notify, and these are marked skipped anyway).
     */
    private function foldDuplicateEscalations(): void
    {
        $groups = DB::table('tasks')
            ->select('hotel_id', 'guest_id')
            ->where('guest_signal', 'escalation')
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNull('deleted_at')
            ->whereNotNull('guest_id')
            ->groupBy('hotel_id', 'guest_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $open = DB::table('tasks')
                ->where('hotel_id', $group->hotel_id)
                ->where('guest_id', $group->guest_id)
                ->where('guest_signal', 'escalation')
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $keep = $open->shift();
            $merged = collect([$keep->description])
                ->merge($open->pluck('description'))
                ->filter(fn ($text) => $text !== null && trim($text) !== '')
                ->implode("\n\n");

            DB::table('tasks')->where('id', $keep->id)->update(['description' => $merged]);
            DB::table('tasks')->whereIn('id', $open->pluck('id'))->update([
                'status' => 'completed',
                'completed_at' => now(),
                'guest_notice_status' => 'skipped',
                'guest_notice_at' => now(),
            ]);
        }
    }
};
