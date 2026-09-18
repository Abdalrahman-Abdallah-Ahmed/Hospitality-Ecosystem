<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every column is nullable and null means "no restriction", so existing
     * activities keep behaving as always-available.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->date('available_from')->nullable();
            $table->date('available_until')->nullable();
            $table->jsonb('operating_hours')->nullable();
            $table->jsonb('unavailable_periods')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['available_from', 'available_until', 'operating_hours', 'unavailable_periods']);
        });
    }
};
