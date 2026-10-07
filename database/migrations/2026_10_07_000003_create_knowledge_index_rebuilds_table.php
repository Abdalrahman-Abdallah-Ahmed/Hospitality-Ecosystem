<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A super-admin request to re-index knowledge: everything, one hotel, or the
 * global sources only. Each source's job reports its outcome here with an
 * atomic increment, so progress is readable while the rebuild runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_index_rebuilds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 16);
            $table->foreignUuid('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('running');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->jsonb('failures')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE knowledge_index_rebuilds ADD CONSTRAINT knowledge_index_rebuilds_scope_check CHECK (scope IN ('all', 'hotel', 'global'))");
        DB::statement("ALTER TABLE knowledge_index_rebuilds ADD CONSTRAINT knowledge_index_rebuilds_hotel_check CHECK ((scope = 'hotel') = (hotel_id IS NOT NULL))");
        DB::statement("ALTER TABLE knowledge_index_rebuilds ADD CONSTRAINT knowledge_index_rebuilds_status_check CHECK (status IN ('running', 'completed'))");
        DB::statement('ALTER TABLE knowledge_index_rebuilds ADD CONSTRAINT knowledge_index_rebuilds_counts_check CHECK (succeeded + failed <= total)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_index_rebuilds');
    }
};
