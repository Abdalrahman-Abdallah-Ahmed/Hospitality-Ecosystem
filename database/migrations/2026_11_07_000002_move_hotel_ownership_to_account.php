<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership moves from the hotel to the account (spec 025).
 *
 * The index is composite on purpose: Postgres treats nulls as distinct, so a
 * user with no group role or no account never conflicts. A partial index on
 * hotel_group_id alone would read as "this column is unique" to
 * ModelColumnRules::uniqueColumns, which ignores the WHERE, and the generic
 * /api/users rules would then refuse any shared account id.
 *
 * An advisor conversation gets a hotel so a waiting action can't be confirmed
 * from another hotel, where the tools would run against the wrong records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['hotel_group_id', 'group_role'], 'users_one_owner_per_account');
        });

        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->foreignUuid('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_one_owner_per_account');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
