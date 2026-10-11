<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the AI did for a hotel, in one categorized list (SPEC-083):
     * each agent turn or AI job (ai_run), each write it made or attempted
     * (data_change), and each pitch or proactive message (guest_outreach).
     *
     * An action-level index over records that already exist: event_log keeps
     * the field-level history of each record. Append-only, apart from a run
     * back-filling a conversation id it only learnt at the end. Never holds
     * conversation text, prompts, model output or cost.
     */
    public function up(): void
    {
        Schema::create('ai_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('ai_run_id')->nullable();

            $table->string('category');                         // App\Enums\AiActivityCategory
            $table->string('agent');                            // App\Enums\AiAgentName
            $table->string('tool')->nullable();                 // tool or job class basename
            $table->string('action');                           // 'booking.created', 'pitch.offered', 'run.completed'
            $table->string('result');                           // App\Enums\AiActivityResult
            $table->string('reason', 255)->nullable();

            $table->nullableUuidMorphs('target');
            $table->unsignedSmallInteger('changes_count')->default(0);
            $table->string('conversation_id', 36)->nullable();  // agent_conversations.id — package table, no FK
            $table->nullableUuidMorphs('acted_for');            // the staff user or guest the AI acted for

            $table->string('trigger_kind')->nullable();         // App\Enums\AiTriggerKind, ai_run rows
            $table->json('usage')->nullable();                  // ai_run rows: requests and tokens, never cost

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['hotel_id', 'occurred_at']);
            $table->index(['hotel_id', 'category', 'occurred_at']);
            $table->index(['hotel_id', 'conversation_id']);
            $table->index('ai_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_activities');
    }
};
