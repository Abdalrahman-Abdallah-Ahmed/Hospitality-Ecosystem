<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An uploaded reservation file waiting to be mapped (016): the stored file
 * plus the head of its first sheet, so the mapping screen is built without
 * reading the file again. Hard-deleted on discard or expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_import_previews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path');
            $table->jsonb('head_rows')->default(DB::raw("'[]'::jsonb"));
            $table->unsignedInteger('last_row')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['hotel_id', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_import_previews');
    }
};
