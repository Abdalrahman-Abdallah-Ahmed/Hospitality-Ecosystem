<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facts the pitching rules need that the catalogue did not hold. Every
     * column is nullable and null means "unknown": an unknown never blocks
     * an activity, so existing activities keep behaving as they did.
     *
     * No backfill. Guessing an audience from a category name would store an
     * inference in a column that reads as a fact.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('audience')->nullable();                    // App\Enums\ActivityAudience; null = not stated
            $table->unsignedSmallInteger('duration_days')->nullable();  // consecutive days it takes; null = a single day
            $table->unsignedInteger('daily_capacity')->nullable();     // people per day; null = unknown, never gated
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['audience', 'duration_days', 'daily_capacity']);
        });
    }
};
