<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Devices paired through POST /api/pair kept whatever format was sent,
     * while the webhook looks them up by the digits Meta sends — so an admin
     * who paired as "+20…" was never recognised as paired. Brings existing
     * rows to the digits-only form new pairings are now stored in.
     */
    public function up(): void
    {
        DB::table('whats_app_devices')->update([
            'phone_number' => DB::raw("regexp_replace(phone_number, '[^0-9]', '', 'g')"),
        ]);
    }

    /**
     * The original formatting is not recoverable, and nothing depends on it.
     */
    public function down(): void {}
};
