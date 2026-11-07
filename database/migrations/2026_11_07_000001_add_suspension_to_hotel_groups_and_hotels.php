<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hotel_groups', 'hotels'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestampTz('suspended_at')->nullable()->index();
                $table->foreignUuid('suspended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('suspended_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['hotel_groups', 'hotels'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('suspended_by');
                $table->dropColumn(['suspended_at', 'suspended_reason']);
            });
        }
    }
};
