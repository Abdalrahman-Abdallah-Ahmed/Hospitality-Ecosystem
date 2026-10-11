<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which AI capability (the agent of the AI run) a provider call belongs
     * to, so usage and cost can be reported per capability (SPEC-091).
     *
     * `agent` already holds the class that made the call, which answers a
     * different question: the turn classifier and a knowledge-search
     * embedding inside a Concierge turn are Concierge work, not capabilities
     * of their own.
     *
     * The backfill is part of this schema change, done once with bulk
     * statements — the model stays append-only.
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->string('capability', 32)->default('unattributed');
            $table->index(['hotel_group_id', 'occurred_at', 'capability']);
        });

        $this->backfill();
    }

    /**
     * Public so the mapping can be tested against seeded rows.
     */
    public function backfill(): void
    {
        // 1. From the run each call belonged to.
        DB::statement(<<<'SQL'
            UPDATE ai_usage_logs
            SET capability = ai_activities.agent
            FROM ai_activities
            WHERE ai_usage_logs.ai_run_id IS NOT NULL
              AND ai_activities.ai_run_id = ai_usage_logs.ai_run_id
              AND ai_activities.category = 'ai_run'
            SQL);

        // 2. Older rows, from the agent class. The classifier only ever runs
        //    inside a Concierge turn; embeddings and reranking outside a run
        //    have no owner and stay unattributed, visibly.
        $byClass = [
            'GuestConciergeAgent' => 'concierge',
            'AdminAdvisorAgent' => 'admin_advisor',
            'RecommendationAgent' => 'recommendation',
            'InsightsAgent' => 'insights',
            'TurnSignalAgent' => 'concierge',
            'DocumentVisionAgent' => 'document_vision',
            'GuestMemoryAgent' => 'guest_memory',
        ];

        foreach ($byClass as $class => $capability) {
            DB::table('ai_usage_logs')
                ->where('capability', 'unattributed')
                ->where('agent', $class)
                ->update(['capability' => $capability]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropIndex(['hotel_group_id', 'occurred_at', 'capability']);
            $table->dropColumn('capability');
        });
    }
};
