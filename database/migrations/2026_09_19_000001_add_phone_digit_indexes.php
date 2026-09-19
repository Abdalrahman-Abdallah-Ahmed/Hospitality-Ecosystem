<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Guest and user phone numbers are stored as typed, while the WhatsApp
     * webhook sends digits only, so sender recognition compares
     * regexp_replace(phone_number) against the digits. Every inbound message
     * runs those lookups across all hotels; these expression indexes stop
     * each one from being a full table scan.
     */
    private const TABLES = ['guests', 'users', 'whats_app_devices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement(
                "create index {$table}_phone_digits_index on {$table} ((regexp_replace(phone_number, '[^0-9]', '', 'g')))"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("drop index if exists {$table}_phone_digits_index");
        }
    }
};
