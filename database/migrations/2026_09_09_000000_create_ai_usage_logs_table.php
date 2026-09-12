<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What serving each account actually cost us. Append-only, like
     * meter_events and transactions.
     *
     * meter_events counts what the customer used; this counts what we paid a
     * provider to give it to them. They are not the same number, and the gap
     * between them is the business.
     *
     * This table is the one deliberate exception to "store facts, never store
     * prices". cost_usd is money, and it belongs here because it is a
     * historical fact about a completed transaction with a supplier — what we
     * were charged, on a date, at a rate that applied then. That is entirely
     * different from writing what a customer owes onto a usage row, which
     * would go stale the moment our pricing changed.
     */
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Both nullable, and both nullOnDelete: a call whose account
            // cannot be resolved is still a real cost we incurred, and
            // dropping the row would understate the total. An unattributed
            // row is a visible gap; a missing row is an invisible one.
            $table->foreignUuid('hotel_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();

            $table->string('agent');                    // 'GuestConciergeAgent'
            $table->string('provider');                 // 'openai'
            $table->string('model');                    // 'gpt-4o'
            $table->string('operation');                // App\Enums\AiOperation

            // Token counts as the provider reported them, already normalised
            // by laravel/ai: input_tokens EXCLUDES anything served from cache,
            // which is counted separately in cached_tokens and priced at the
            // cached rate. Adding the two together would overstate the bill.
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('tool_calls')->default(0);

            // Six decimals because a single call may cost $0.000420. Round to
            // two and the entire cost base becomes zero.
            $table->decimal('cost_usd', 12, 6);

            // Never sum an estimated cost into a measured total without
            // saying so. Estimated rows are counted and reported as a
            // percentage by the cost endpoint.
            $table->boolean('cost_is_estimated')->default(false);

            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('succeeded')->default(true);
            $table->string('failure_reason')->nullable();

            // Retries multiply cost: a call that retried three times cost us
            // four calls, and all four are logged.
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->nullableUuidMorphs('trigger');
            $table->string('trigger_kind');             // App\Enums\AiTriggerKind
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['hotel_group_id', 'occurred_at']);
            $table->index(['model', 'occurred_at']);
            $table->index(['trigger_kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
