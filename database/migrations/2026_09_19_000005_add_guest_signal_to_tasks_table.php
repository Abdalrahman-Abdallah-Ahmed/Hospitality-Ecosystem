<?php

use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a guest-related task exists: an escalation, a service request, or a
     * follow-up to help an interested guest book. Pitching reads it to stay
     * quiet while a guest has a problem open.
     *
     * Backfilled only where the existing row says so with certainty. Guest
     * tasks were all created by the service-request tool, and escalations
     * carry a fixed title. Anything else stays null.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('guest_signal')->nullable();   // App\Enums\GuestSignal
            $table->index(['guest_id', 'guest_signal', 'created_at']);
        });

        DB::table('tasks')
            ->where('created_by', CreatedBy::GUEST->value)
            ->whereNotNull('guest_id')
            ->update(['guest_signal' => GuestSignal::SERVICE_REQUEST->value]);

        DB::table('tasks')
            ->where('created_by', CreatedBy::AI->value)
            ->where('title', 'Guest needs human assistance')
            ->whereNotNull('guest_id')
            ->update(['guest_signal' => GuestSignal::ESCALATION->value]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['guest_id', 'guest_signal', 'created_at']);
            $table->dropColumn('guest_signal');
        });
    }
};
