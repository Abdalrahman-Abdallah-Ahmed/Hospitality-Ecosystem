<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each account used. Append-only, the same discipline as the
     * transaction ledger: no softDeletes and no update path. A counter built
     * from these rows can be thrown away and rebuilt; these rows cannot.
     *
     * There is deliberately NO price column and NO subscription_id.
     *
     * A meter event records what happened, never what it was worth. Writing
     * "this cost the customer 0.02" onto the row makes every historical
     * event wrong the moment pricing changes, and unrecoverable, because the
     * quantity is then buried under a stale rate. Keep quantity and price in
     * different places and any future pricing model — flat, per message, per
     * booking, tiered, or a bespoke enterprise deal — reads these same rows
     * and works immediately, with full history.
     *
     * subscription_id is absent for a simpler reason: subscriptions do not
     * exist yet (WP-7 is deferred). A nullable foreign key to a table nobody
     * has built is an invitation to half-implement it. Adding the column
     * later is a trivial migration; unpicking a wrong one is not.
     */
    public function up(): void
    {
        Schema::create('meter_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // restrictOnDelete, not cascade: usage history is the evidence
            // behind a future invoice, so it must not disappear because
            // someone hard-deleted an account. Hotels restrict their group
            // for the same reason. (The spec sketches cascade here; append-
            // only is the stronger of the two rules.)
            $table->foreignUuid('hotel_group_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();

            $table->string('feature_code');                 // App\Enums\MeterFeature
            $table->unsignedBigInteger('quantity')->default(1);
            $table->string('unit');

            $table->nullableUuidMorphs('source');           // the record that caused it
            $table->nullableUuidMorphs('actor');
            $table->string('actor_kind');                   // App\Enums\ActorKind

            // The calendar month containing occurred_at, denormalised so a
            // period total is a grouped read rather than a date computation
            // per row. This is a PLACEHOLDER, not a decision: when
            // subscriptions exist, the period comes from the subscription's
            // own dates and stops being aligned to the calendar.
            $table->date('period_start');

            $table->timestamp('occurred_at');
            $table->string('idempotency_key')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();   // NO softDeletes — append only

            // Postgres treats NULL idempotency_key as distinct, so events
            // recorded without one are unaffected by this constraint.
            $table->unique(['hotel_group_id', 'idempotency_key']);
            $table->index(['hotel_group_id', 'feature_code', 'period_start']);
            $table->index(['hotel_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_events');
    }
};
