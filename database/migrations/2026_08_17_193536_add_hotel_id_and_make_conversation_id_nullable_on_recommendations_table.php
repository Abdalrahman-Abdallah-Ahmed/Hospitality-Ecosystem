<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->foreignUuid('hotel_id')->nullable()->after('activity_id')->constrained()->cascadeOnDelete();
        });

        DB::statement('
            UPDATE recommendations
            SET hotel_id = reservations.hotel_id
            FROM reservations
            WHERE reservations.id = recommendations.reservation_id
        ');

        DB::statement('ALTER TABLE recommendations ALTER COLUMN hotel_id SET NOT NULL');

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE recommendations ALTER COLUMN conversation_id SET NOT NULL');

        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_id');
        });
    }
};
