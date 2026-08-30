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
        Schema::table('guests', function (Blueprint $table) {
            // A normalised, hashed fingerprint (lowercased email, or
            // digits-only phone) used to recognize the same person arriving
            // through a different channel, so guest creation can reuse the
            // existing row instead of creating a duplicate.
            $table->string('identity_hash')->nullable()->index();
            $table->timestamp('identity_resolved_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['identity_hash', 'identity_resolved_at']);
        });
    }
};
