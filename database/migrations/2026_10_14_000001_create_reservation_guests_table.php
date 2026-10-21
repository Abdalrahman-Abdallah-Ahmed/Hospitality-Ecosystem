<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hotel_id')->index();
            $table->uuid('reservation_id')->index();
            $table->uuid('guest_id')->index();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX reservation_guests_live_guest_unique ON reservation_guests (reservation_id, guest_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX reservation_guests_live_primary_unique ON reservation_guests (reservation_id) WHERE is_primary = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
