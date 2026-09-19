<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per guest turn: whether pitching was allowed, and why or why
     * not. Rows exist for turns with no pitch too — the only way to tell
     * "the rules blocked it" from "the agent had an opening and stayed silent".
     *
     * The decision half is written once, before the agent runs. The
     * completion half is written once, after the reply.
     */
    public function up(): void
    {
        Schema::create('pitch_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();
            $table->string('conversation_id', 36)->nullable();   // agent_conversations.id — package table, no FK

            // Decision: written once, before the agent runs. Never updated.
            $table->boolean('eligible');
            $table->json('gates');                               // [{gate, passed, detail}] in evaluation order
            $table->boolean('classifier_ran')->default(false);
            $table->boolean('complaint')->nullable();            // null = classifier did not run
            $table->string('opening')->nullable();               // App\Enums\PitchOpening
            $table->boolean('explicit_request')->nullable();
            $table->text('opening_quote')->nullable();           // the guest's own words
            $table->foreignUuid('interest_category_id')->nullable()
                ->constrained('activity_categories')->nullOnDelete();
            $table->json('candidates')->nullable();              // shortlist + exclusions
            $table->json('signals')->nullable();                 // inputs used: party, segment
            $table->string('rules_version');
            $table->timestamp('decided_at');

            // Completion: written once, after the reply.
            $table->string('result')->nullable();                // App\Enums\PitchResult; null = job died mid-turn
            $table->foreignUuid('recommendation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('chosen_rank')->nullable();
            $table->boolean('mention_verified')->nullable();     // activity name found in the reply text
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();                                // no softDeletes — a record of decisions

            $table->index(['hotel_id', 'decided_at']);
            $table->index(['stay_id', 'result']);
        });

        Schema::table('recommendations', function (Blueprint $table) {
            // Set only for recommendations delivered by a pitch. Null =
            // generated for staff and never pitched in chat.
            $table->foreignUuid('pitch_decision_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pitch_decision_id');
        });

        Schema::dropIfExists('pitch_decisions');
    }
};
