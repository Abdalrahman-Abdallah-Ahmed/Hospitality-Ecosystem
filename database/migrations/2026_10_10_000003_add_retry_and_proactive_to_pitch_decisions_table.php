<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_retry: the staged pitch is the one retry allowed after a decline
     * (D10). proactive_message_id: the decision was a proactive pitch, not a
     * guest turn.
     */
    public function up(): void
    {
        Schema::table('pitch_decisions', function (Blueprint $table) {
            $table->boolean('is_retry')->default(false)->after('chosen_rank');
            $table->foreignUuid('proactive_message_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pitch_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proactive_message_id');
            $table->dropColumn('is_retry');
        });
    }
};
