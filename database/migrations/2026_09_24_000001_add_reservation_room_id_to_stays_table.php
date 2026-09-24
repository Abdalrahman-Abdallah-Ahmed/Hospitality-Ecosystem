<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stay belongs to one reservation line (SPEC-023). Nullable: legacy stays
 * written without a reservation (direct imports) have no line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->foreignUuid('reservation_room_id')->nullable()->after('reservation_id')
                ->constrained('reservation_rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_room_id');
        });
    }
};
