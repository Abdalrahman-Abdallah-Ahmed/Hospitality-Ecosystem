<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per proactive message the Concierge may send (SPEC-073): what
     * caused it, and how it ended — sent, deferred, skipped or failed, and
     * why. The row is the record; it is never deleted.
     */
    public function up(): void
    {
        Schema::create('proactive_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();

            $table->string('trigger');                           // App\Enums\ProactiveTrigger
            $table->string('event_key', 100);                    // e.g. booking:{id}; one message per event
            $table->foreignUuid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recommendation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status');                            // App\Enums\ProactiveMessageStatus
            $table->string('reason')->nullable();                // App\Enums\ProactiveSkipReason
            $table->timestamp('due_at');
            $table->timestamp('valid_until');
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->string('locale', 5)->nullable();
            $table->text('body')->nullable();                    // the text actually sent
            $table->string('conversation_id', 36)->nullable();   // agent_conversations.id — package table, no FK
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();                                // no softDeletes — a record of decisions

            $table->unique(['hotel_id', 'guest_id', 'trigger', 'event_key']);
            $table->index(['status', 'due_at']);
            $table->index(['hotel_id', 'guest_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_messages');
    }
};
