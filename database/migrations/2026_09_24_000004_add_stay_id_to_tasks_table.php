<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can belong to one guest stay (FR-018): the cleaning after its
 * check-out, or a request made while the guest was in the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('stay_id')->nullable()->after('reservation_id')->constrained()->nullOnDelete();
            $table->index('stay_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['stay_id']);
            $table->dropConstrainedForeignId('stay_id');
        });
    }
};
