<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('reserved');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reservation_id', 'status']);
            $table->index(['hotel_id', 'room_id']);
            $table->index(['hotel_id', 'room_type_id']);
        });

        // One physical room cannot sit on two live lines of the same
        // reservation. Overlap across reservations is SPEC-021's job.
        DB::statement(
            'CREATE UNIQUE INDEX reservation_rooms_reservation_room_unique ON reservation_rooms (reservation_id, room_id) '
            ."WHERE room_id IS NOT NULL AND status <> 'cancelled' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_rooms');
    }
};
