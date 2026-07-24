<?php

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
