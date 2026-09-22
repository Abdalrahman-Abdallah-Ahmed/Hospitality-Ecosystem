<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->index('hotel_id');
            $table->index(['hotel_id', 'deleted_at']);
            $table->index('is_active');
            $table->index(['hotel_id', 'is_active']);
        });

        // Names are unique per hotel among live rows only, case-insensitively,
        // so a deleted type's name can be reused.
        DB::statement('CREATE UNIQUE INDEX room_types_hotel_id_name_unique ON room_types (hotel_id, lower(name)) WHERE deleted_at IS NULL');

        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_max_occupancy_check CHECK (max_occupancy >= 1)');
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_adult_capacity_check CHECK (adult_capacity >= 1)');
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_child_capacity_check CHECK (child_capacity >= 0)');
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_capacity_sum_check CHECK (adult_capacity + child_capacity <= max_occupancy)');
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_base_price_check CHECK (base_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
