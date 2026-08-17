<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->decimal('predicted_confidence', 5, 2)->default(0)->after('reason');
            $table->decimal('guest_confidence', 5, 2)->nullable()->after('predicted_confidence');
            $table->dropColumn('confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->decimal('confidence', 5, 2)->default(0)->after('reason');
            $table->dropColumn(['predicted_confidence', 'guest_confidence']);
        });
    }
};
