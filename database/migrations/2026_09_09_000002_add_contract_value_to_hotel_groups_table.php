<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Margin needs revenue, and there is no billing system yet (WP-7 and
     * WP-11 are deferred). Two nullable columns close that gap without
     * building one.
     *
     * This is a HAND-FILLED figure, recorded from whatever was manually
     * agreed with the account — not an invoice, not a subscription, and not
     * evidence of one. The cost endpoint labels it as such wherever it
     * appears, and returns a null margin rather than a guess where no value
     * has been entered.
     *
     * Superseded cleanly when subscriptions arrive: the columns are dropped
     * and margin comes from the real contract instead.
     */
    public function up(): void
    {
        Schema::table('hotel_groups', function (Blueprint $table) {
            $table->decimal('contract_value_monthly', 10, 2)->nullable();
            $table->string('contract_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_groups', function (Blueprint $table) {
            $table->dropColumn(['contract_value_monthly', 'contract_currency']);
        });
    }
};
