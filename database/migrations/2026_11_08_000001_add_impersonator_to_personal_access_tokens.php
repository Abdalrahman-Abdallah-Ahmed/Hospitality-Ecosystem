<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a token as an impersonation session (spec 027). Null on every other
     * token. A super admin's sessions go when that super admin does.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('impersonator_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['impersonator_id']);
            $table->dropColumn('impersonator_id');
        });
    }
};
