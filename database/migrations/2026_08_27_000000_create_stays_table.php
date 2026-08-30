<?php

use App\Enums\StayStatus;
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
        Schema::create('stays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->nullOnDelete();

            // Planned (from the booking).
            $table->date('planned_arrival_date');
            $table->date('planned_departure_date');

            // Actual (what really happened) — null until it happens. Never
            // overwrite planned_* with these; a shortened/extended stay is
            // evidence in itself, not something to discard.
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();

            $table->string('status', 20)->default(StayStatus::EXPECTED->value);
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->unsignedInteger('nights')->nullable();
            $table->decimal('room_revenue', 12, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('market_segment')->nullable();
            $table->string('source_channel')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('reservation_id');
            $table->index(['hotel_id', 'planned_arrival_date']);
            $table->index(['hotel_id', 'planned_departure_date']);
            $table->index(['hotel_id', 'status']);
            $table->index(['guest_id', 'checked_in_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
