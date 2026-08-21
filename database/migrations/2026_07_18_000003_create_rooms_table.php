<?php

use App\Enums\RoomStatusesEnum;
use App\Enums\RoomTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('room_number')->nullable();
            $table->enum('room_type', RoomTypes::cases())->nullable();
            $table->string('floor')->nullable();
            $table->enum('status', RoomStatusesEnum::cases())->default('available');
            $table->timestamps();

            $table->unique(['hotel_id', 'room_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
