<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per guest per reservation whose memories were (or were not)
     * extracted. The unique key is what makes extraction run once, whatever
     * re-triggers it.
     */
    public function up(): void
    {
        Schema::create('guest_memory_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();

            $table->string('status');                           // App\Enums\GuestMemoryExtractionStatus
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->unsignedSmallInteger('memories_written')->default(0);
            $table->uuid('ai_run_id')->nullable();
            $table->string('last_error', 255)->nullable();

            $table->timestamps();

            $table->unique(['hotel_id', 'guest_id', 'reservation_id']);
            $table->index(['status', 'retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_memory_extractions');
    }
};
