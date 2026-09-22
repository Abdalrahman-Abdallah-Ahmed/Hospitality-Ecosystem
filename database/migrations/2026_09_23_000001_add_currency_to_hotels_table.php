<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * hotels.currency already ships with create_hotels_table, so this only
     * adds it to a database that somehow lacks it and never drops it on rollback.
     */
    public function up(): void
    {
        if (Schema::hasColumn('hotels', 'currency')) {
            return;
        }

        Schema::table('hotels', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD');
        });
    }

    public function down(): void
    {
        //
    }
};
