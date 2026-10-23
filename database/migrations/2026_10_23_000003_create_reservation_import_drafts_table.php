<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapped and resolved reservations waiting for the write phase. All of an
 * import's drafts are deleted when it completes or fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_import_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->jsonb('payload');
            $table->timestampsTz();

            $table->unique(['reservation_import_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_import_drafts');
    }
};
