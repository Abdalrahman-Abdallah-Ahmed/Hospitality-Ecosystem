<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a guest's external_id came from. `import` marks an old-system guest
 * id from a reservation import, kept apart from WhatsApp and OTA ids, and
 * unique per hotel (the channel-keyed index treats NULL channels as distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('external_source', 16)->nullable();
        });

        // lower(): the import matches old-system ids ignoring case.
        DB::statement("CREATE UNIQUE INDEX guests_import_external_id_unique ON guests (hotel_id, lower(external_id)) WHERE external_source = 'import' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS guests_import_external_id_unique');

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('external_source');
        });
    }
};
