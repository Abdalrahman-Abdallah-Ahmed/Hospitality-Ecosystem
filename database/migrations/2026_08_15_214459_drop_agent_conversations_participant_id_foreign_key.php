<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * participant_id is a polymorphic reference (paired with participant_type),
 * not a plain foreign key to a single table — it can point at either
 * `users` (AdminAdvisorAgent) or `guests` (GuestConciergeAgent). The FK
 * added in 2026_08_15_155951_fix_agent_conversations_participant_id_nullable_and_cascade.php
 * only allowed `users`, so any guest conversation violated the constraint
 * and poisoned the transaction. Polymorphic columns can't have a single-
 * table FK, so it's dropped rather than pointed at a second table.
 */
return new class extends AiMigration
{
    public function up(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversationsTable, function (Blueprint $table) {
            $table->dropForeign(['participant_id']);
        });

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->dropForeign(['participant_id']);
        });
    }

    public function down(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversationsTable, function (Blueprint $table) {
            $table->foreign('participant_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->foreign('participant_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
