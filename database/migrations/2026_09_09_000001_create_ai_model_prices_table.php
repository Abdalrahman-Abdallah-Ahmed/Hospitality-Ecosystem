<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each model costs, and when it cost that.
     *
     * Prices are dated, not constant. Providers change their pricing, and a
     * cost figure for August computed at November's rates is not August's
     * cost — it is a number nobody can reconcile against an invoice.
     *
     * Same supersession discipline as everywhere else in this system: a price
     * change closes the old row with an effective_to and opens a new one.
     * Nothing is silently overwritten, so any past total can be recomputed
     * and will come out the same.
     */
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('model');

            // Per million tokens, which is how every provider quotes. Four
            // decimals holds even the cheapest embedding model.
            $table->decimal('input_price_per_million', 12, 4);
            $table->decimal('output_price_per_million', 12, 4);

            // Nullable because not every provider offers cached input. When
            // it is null the full input rate is applied, which may overstate
            // slightly — the safe direction for a cost figure.
            $table->decimal('cached_input_price_per_million', 12, 4)->nullable();

            $table->string('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();   // null = still current
            $table->timestamps();

            $table->index(['provider', 'model', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
