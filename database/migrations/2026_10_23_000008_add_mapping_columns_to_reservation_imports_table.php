<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an import was read with (016): the preview it came from, its header
 * row, the confirmed column map (null for a direct upload, which matches
 * headers by name) and how amounts' decimal marks are read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_imports', function (Blueprint $table) {
            $table->foreignUuid('reservation_import_preview_id')->nullable()->after('uploaded_by')
                ->constrained('reservation_import_previews')->nullOnDelete();
            $table->unsignedSmallInteger('header_row')->default(1)->after('date_order');
            $table->jsonb('column_map')->nullable()->after('header_row');
            $table->string('decimal_mark', 8)->default('auto')->after('column_map');
        });

        DB::statement("ALTER TABLE reservation_imports ADD CONSTRAINT reservation_imports_decimal_mark_check CHECK (decimal_mark IN ('auto', 'dot', 'comma'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservation_imports DROP CONSTRAINT IF EXISTS reservation_imports_decimal_mark_check');

        Schema::table('reservation_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_import_preview_id');
            $table->dropColumn(['header_row', 'column_map', 'decimal_mark']);
        });
    }
};
