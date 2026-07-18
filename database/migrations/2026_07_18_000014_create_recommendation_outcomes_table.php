<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recommendation_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('details')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_outcomes');
    }
};
