<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable facts about a guest at one hotel, extracted once after a stay
     * (SPEC-084). Hints for the Concierge, never PMS data. No soft deletes:
     * deleting a memory, or the guest, removes it for good.
     */
    public function up(): void
    {
        Schema::create('guest_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();

            $table->string('category');                 // App\Enums\GuestMemoryCategory
            $table->string('content', 200);             // one sentence, in `language`
            $table->string('language', 2);              // en | ar
            $table->string('confidence');               // App\Enums\GuestMemoryConfidence (medium | high)
            $table->foreignUuid('source_stay_id')->nullable()->constrained('stays')->nullOnDelete();
            $table->timestamp('extracted_at');

            $table->timestamps();

            $table->index(['hotel_id', 'guest_id', 'extracted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_memories');
    }
};
