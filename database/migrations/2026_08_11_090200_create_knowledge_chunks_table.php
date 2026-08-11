<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('chunkable');
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->nullable();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('content');
            $table->unsignedInteger('token_count')->nullable();
            $table->json('metadata')->nullable();
            $table->vector('embedding', dimensions: 1536);
            $table->timestamps();

            $table->index('hotel_id');
            $table->vectorIndex('embedding');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
