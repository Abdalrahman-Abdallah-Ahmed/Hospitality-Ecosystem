<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each hotel's last confirmed import column mapping (016), suggested for its
 * next upload. Keyed by normalized header text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_import_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('header_row')->default(1);
            $table->jsonb('columns')->default(DB::raw("'{}'::jsonb"));
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_import_mappings');
    }
};
