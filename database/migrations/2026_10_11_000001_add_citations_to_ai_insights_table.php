<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The knowledge sources an insight relied on, as they were when it was
     * generated (SPEC-090) — kept apart from evidence_sources, which names
     * the operational records it is about. Existing insights read as [].
     *
     * Also admits the `knowledge` category: an insight resting only on
     * knowledge, never above hypothesis level.
     */
    public function up(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->jsonb('citations')->default(DB::raw("'[]'::jsonb"));
        });

        DB::statement('ALTER TABLE ai_insights DROP CONSTRAINT IF EXISTS ai_insights_category_check');
        DB::statement("ALTER TABLE ai_insights ADD CONSTRAINT ai_insights_category_check CHECK (category IN ('reservation', 'task', 'guest_message', 'general', 'knowledge'))");
    }

    public function down(): void
    {
        DB::table('ai_insights')->where('category', 'knowledge')->update(['category' => 'general']);

        DB::statement('ALTER TABLE ai_insights DROP CONSTRAINT IF EXISTS ai_insights_category_check');
        DB::statement("ALTER TABLE ai_insights ADD CONSTRAINT ai_insights_category_check CHECK (category IN ('reservation', 'task', 'guest_message', 'general'))");

        Schema::table('ai_insights', function (Blueprint $table) {
            $table->dropColumn('citations');
        });
    }
};
