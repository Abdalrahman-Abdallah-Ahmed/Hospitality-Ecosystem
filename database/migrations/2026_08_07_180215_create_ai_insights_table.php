<?php

use App\Enums\AiInsightCategories;
use App\Enums\InsightTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->nullableUuidMorphs('insightable');
            $table->string('title');
            $table->text('description');
            $table->enum('category', AiInsightCategories::cases());
            $table->enum('insight_type', InsightTypes::cases())->default(InsightTypes::GENERAL);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
