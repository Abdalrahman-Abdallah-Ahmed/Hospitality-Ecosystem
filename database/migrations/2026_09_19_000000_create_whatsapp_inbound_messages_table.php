<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per inbound WhatsApp message, keyed by Meta's message id.
     *
     * Meta redelivers a webhook it did not see acknowledged in time, so the
     * same message can arrive more than once. The unique wamid is what stops
     * a redelivery from running the agent (and its bookings/tasks) twice. The
     * row also holds the generated reply, so a failed send can be retried
     * without asking the model again.
     */
    public function up(): void
    {
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: a payload without an id is still answered, just not deduplicated.
            $table->string('wamid')->nullable()->unique();
            $table->string('phone_number');
            $table->string('message_type')->default('text');
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->text('reply_text')->nullable();
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['phone_number', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');
    }
};
