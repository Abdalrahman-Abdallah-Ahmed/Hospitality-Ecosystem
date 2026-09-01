<?php

use App\Enums\EvidenceLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The commercial ledger — "the till". Append-only: there is deliberately
     * no softDeletes and no update path. A mistake is corrected with a new
     * reversing row that points back at the original via
     * reverses_transaction_id, the same way accounting has worked for
     * centuries.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();

            // Attribution — all nullable: a transaction that cannot be tied to
            // a stay within its window is left unattributed (and marked L4)
            // rather than guessed at. An honest gap beats a confident guess.
            $table->foreignUuid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('activity_id')->nullable()->constrained()->nullOnDelete();

            // What was sold.
            $table->string('item_name');                    // human-readable, always stored
            $table->string('revenue_center')->nullable();    // spa, diving, restaurant, excursions...
            $table->string('department')->nullable();

            // Money — the three fields that must never be conflated. An
            // "amount" column in source data has been seen to mean unit price
            // in some rows and line total in others; never store an ambiguous
            // amount. unit_price is nullable so the ambiguous/unverified path
            // can fill only line_total and downgrade the row to L4.
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->default(0);   // the only field reports sum
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('currency', 3);

            // When and who.
            $table->timestamp('transacted_at');             // when the sale happened
            $table->date('business_date');                  // the hotel's accounting day
            $table->string('seller_reference')->nullable();
            $table->foreignUuid('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Provenance and integrity.
            $table->string('source_system');                // App\Enums\TransactionSource
            $table->string('external_reference')->nullable();
            $table->string('evidence_level', 4)->default(EvidenceLevel::L1->value);
            $table->uuid('reverses_transaction_id')->nullable();
            $table->json('raw_payload')->nullable();         // the original row, untouched

            $table->timestamps();   // NO softDeletes — ledgers are append-only

            // Idempotent imports: the same source row cannot land twice.
            // Postgres treats NULL external_reference as distinct, so
            // hand-entered rows without a reference are unaffected.
            $table->unique(['hotel_id', 'source_system', 'external_reference']);
            $table->index(['hotel_id', 'business_date']);
            $table->index('stay_id');
            $table->index(['guest_id', 'transacted_at']);
        });

        // Added after creation: a self-referential foreign key cannot be
        // declared inside the same CREATE TABLE on Postgres.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('reverses_transaction_id')
                ->references('id')->on('transactions')->nullOnDelete();
            $table->index('reverses_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
