<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->jsonb('permissions');
            $table->timestamps();
            $table->softDeletes();
        });

        // Unique among live roles only, so a deleted role's name can be reused.
        DB::statement('CREATE UNIQUE INDEX staff_roles_hotel_id_name_unique ON staff_roles (hotel_id, name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_roles');
    }
};
