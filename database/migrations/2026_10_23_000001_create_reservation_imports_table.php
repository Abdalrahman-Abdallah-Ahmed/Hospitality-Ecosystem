<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per reservation import upload (SPEC-100/101): its status, progress,
 * totals and the report-level lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->string('date_order', 16)->default('day_first');
            $table->string('status', 16)->default('queued');
            $table->string('stage', 16)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('duplicate')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('unresolved')->default(0);
            $table->jsonb('created_rooms')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('created_room_types')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('unrecognized_columns')->default(DB::raw("'[]'::jsonb"));
            $table->text('failure_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['hotel_id', 'status', 'created_at']);
        });

        DB::statement("ALTER TABLE reservation_imports ADD CONSTRAINT reservation_imports_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE reservation_imports ADD CONSTRAINT reservation_imports_stage_check CHECK (stage IS NULL OR stage IN ('parse', 'normalize', 'validate', 'map', 'import', 'report'))");
        DB::statement("ALTER TABLE reservation_imports ADD CONSTRAINT reservation_imports_date_order_check CHECK (date_order IN ('day_first', 'month_first'))");
        DB::statement('ALTER TABLE reservation_imports ADD CONSTRAINT reservation_imports_totals_check CHECK (imported + skipped + duplicate + invalid + unresolved <= total_rows)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_imports');
    }
};
