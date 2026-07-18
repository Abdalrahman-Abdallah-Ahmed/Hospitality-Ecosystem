<?php

use App\Enums\KnowledgeBaseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('category')->default(KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES->value);
            $table->longText('content');
            $table->json('tags')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_articles');
    }
};
