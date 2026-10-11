<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-hotel switch for guest memories (SPEC-084 FR-032). On by default,
     * existing hotels included.
     */
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->boolean('guest_memories_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('guest_memories_enabled');
        });
    }
};
