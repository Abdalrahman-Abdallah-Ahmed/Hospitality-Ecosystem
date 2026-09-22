<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hotel_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('max_occupancy');
            $table->unsignedInteger('adult_capacity');
            $table->unsignedInteger('child_capacity');
            $table->json('bed_configuration')->nullable();
            $table->json('amenities')->nullable();
            $table->decimal('base_price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->onDelete('cascade');
            $table->unique(['hotel_id', 'name']);
            $table->index('hotel_id');
            $table->index(['hotel_id', 'deleted_at']);
            $table->index('is_active');
            $table->index(['hotel_id', 'is_active']);

            $table->check('max_occupancy >= 1');
            $table->check('adult_capacity >= 1');
            $table->check('child_capacity >= 0');
            $table->check('adult_capacity + child_capacity <= max_occupancy');
            $table->check('base_price >= 0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
