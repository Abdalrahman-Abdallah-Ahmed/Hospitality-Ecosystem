<?php

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn('conversation_id');
        });

        // These rows point at the App\Models\Message rows this migration is about to
        // delete along with the whole `messages` table; the class itself is gone too,
        // so leaving them would fatal the next time ->insightable is loaded.
        DB::table('ai_insights')->where('insightable_type', 'App\\Models\\Message')->delete();

        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('sender_id')->nullable();
            $table->string('sender_type')->nullable();
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(ConversationStatus::OPEN->value);
            $table->string('channel')->default(ConversationChannel::WHATSAPP->value);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->uuid('sender_id')->nullable();
            $table->string('sender_type')->nullable();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('content');
            $table->string('message_type')->default(MessageType::TEXT->value);
            $table->boolean('is_ai_generated')->default(false);
            $table->string('delivery_status')->default(MessageDeliveryStatus::PENDING->value);
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('conversation_id')->nullable()->after('hotel_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
        });
    }
};
