<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The switch search filters on. Deactivating, deleting or restoring a
 * document flips its passages here in the same request, without re-embedding
 * anything. Existing rows are correct as active: today a source's chunks only
 * exist while it is published or active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        DB::statement('CREATE INDEX knowledge_chunks_active_hotel_id_index ON knowledge_chunks (hotel_id) WHERE is_active');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_active_hotel_id_index');

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
