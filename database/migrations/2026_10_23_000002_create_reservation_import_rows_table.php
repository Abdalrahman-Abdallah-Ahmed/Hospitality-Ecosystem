<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to each data row of an import: the reconciliation report
 * (FR-025–FR-029). Holds no guest phone numbers or emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reservation_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('external_reference')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->string('stage', 16);
            $table->jsonb('fields')->default(DB::raw("'[]'::jsonb"));
            $table->text('reason')->nullable();
            $table->jsonb('warnings')->default(DB::raw("'[]'::jsonb"));
            $table->foreignUuid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('draft_id')->nullable()->index();
            $table->timestampsTz();

            $table->unique(['reservation_import_id', 'row_number']);
            $table->index(['reservation_import_id', 'outcome']);
        });

        DB::statement("ALTER TABLE reservation_import_rows ADD CONSTRAINT reservation_import_rows_outcome_check CHECK (outcome IS NULL OR outcome IN ('imported', 'skipped', 'duplicate', 'invalid', 'unresolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_import_rows');
    }
};
