<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * Repairs 2026_07_22_125327_create_agent_conversations_table.php for environments
 * that already ran it. That migration's name was recorded as "Ran" back when its
 * content still created participant_id as a plain unsignedBigInteger with no
 * foreign key — incompatible with this app's uuid users.id — before it was later
 * edited in place to a (buggy) foreignUuid()->constrained()->nullable(). Since
 * editing an already-run migration's file never re-executes it, already-migrated
 * databases are still on that original bigint schema. Both tables are empty, so
 * this just drops and recreates the column at the correct type/constraint rather
 * than attempting an in-place type conversion. Dropping the column also drops the
 * composite indexes that reference it, so those are recreated afterward.
 */
return new class extends AiMigration
{
    public function up(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversationsTable, function (Blueprint $table) {
            $table->dropColumn('participant_id');
        });

        Schema::table($conversationsTable, function (Blueprint $table) {
            $table->foreignUuid('participant_id')->nullable()->after('participant_type')
                ->constrained('users', 'id')->nullOnDelete();

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->dropColumn('participant_id');
        });

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->foreignUuid('participant_id')->nullable()->after('participant_type')
                ->constrained('users', 'id')->nullOnDelete();

            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }

    public function down(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversationsTable, function (Blueprint $table) {
            $table->dropConstrainedForeignId('participant_id');
        });

        Schema::table($conversationsTable, function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->nullable()->after('participant_type');

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->dropConstrainedForeignId('participant_id');
        });

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->nullable()->after('participant_type');

            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }
};
