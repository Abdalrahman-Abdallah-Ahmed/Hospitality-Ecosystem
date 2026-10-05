<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why, since when and by whom a room is out of order (SPEC-033), and the
 * maintenance task tracking the repair. The expected end date is for staff
 * only; availability never reads it. All of them are cleared on return to
 * service. Runs before the status split, which writes the reason and time for
 * the rooms it moves to out of order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->text('out_of_order_reason')->nullable();
            $table->timestampTz('out_of_order_since')->nullable();
            $table->date('out_of_order_until')->nullable();
            $table->foreignUuid('out_of_order_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('out_of_order_task_id')->nullable()->constrained('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('out_of_order_task_id');
            $table->dropConstrainedForeignId('out_of_order_by_user_id');
            $table->dropColumn(['out_of_order_reason', 'out_of_order_since', 'out_of_order_until']);
        });
    }
};
