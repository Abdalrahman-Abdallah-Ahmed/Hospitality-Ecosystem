<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links settlement back to the commitment that produced it — loosely.
     *
     * When a transaction carries a booking_id or a matching reference, link
     * it. When it does not, leave it unlinked: an unlinked transaction is a
     * walk-up sale, which is normal and expected. There is deliberately no
     * inference between bookings and transactions in Phase 1 — the same
     * temptation the stay-window rule exists to resist.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booking_reference', 8)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn('booking_reference');
        });
    }
};
