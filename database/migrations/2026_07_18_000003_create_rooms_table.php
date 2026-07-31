<?php

use App\Enums\RoomStatusesEnum;
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
            $table->string('room_number')->unique()->nullable();
            $table->string('room_type')->nullable();
            $table->string('floor')->nullable();
            $table->enum('status', RoomStatusesEnum::cases())->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
