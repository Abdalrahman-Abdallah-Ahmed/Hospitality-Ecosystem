<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hotel_id');
            $table->string('name', 120);
            $table->string('language', 2);
            $table->boolean('is_default')->default(false);
            $table->string('disk');
            $table->string('original_path');
            $table->string('render_path');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width_px');
            $table->unsignedInteger('height_px');
            $table->jsonb('layout')->default(DB::raw("'[]'::jsonb"));
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hotel_id')->references('id')->on('hotels')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('hotel_id');
        });

        // Names are unique per hotel among live rows, case-insensitively, so a
        // deleted template's name can be reused.
        DB::statement('CREATE UNIQUE INDEX voucher_templates_hotel_id_name_unique ON voucher_templates (hotel_id, lower(name)) WHERE deleted_at IS NULL');

        // At most one default per language per hotel (FR-013).
        DB::statement('CREATE UNIQUE INDEX voucher_templates_hotel_id_language_default_unique ON voucher_templates (hotel_id, language) WHERE is_default AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_templates');
    }
};
