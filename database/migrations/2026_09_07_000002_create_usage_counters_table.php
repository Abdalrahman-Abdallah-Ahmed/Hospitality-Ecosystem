<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cache of period totals, not a source of truth. Every row here is
     * derived from meter_events (or, for seats, recounted from the table
     * that owns them) and can be deleted and rebuilt at any time.
     *
     * cascadeOnDelete, unlike meter_events: this table is disposable by
     * design, so there is nothing to protect.
     */
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_group_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code');
            $table->date('period_start');
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamp('recomputed_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_group_id', 'feature_code', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
