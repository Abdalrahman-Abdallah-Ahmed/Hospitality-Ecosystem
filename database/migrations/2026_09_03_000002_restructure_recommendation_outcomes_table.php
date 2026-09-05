<?php

use App\Enums\AttributionMethod;
use App\Enums\EvidenceLevel;
use App\Enums\OutcomeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns free-text outcomes into structured, queryable ones.
     *
     * The conversion link is `booking_id`, not a payment: a recommendation
     * succeeds when it produces a commitment. Money is recorded separately in
     * the ledger and may never arrive at all.
     *
     * `attribution_method` is NOT NULL with no default on purpose — every row
     * must state how we found out, and a default would let unlabelled rows
     * slip in. `evidence_level` is likewise NOT NULL and always derived from
     * the method; see AttributionMethod.
     */
    public function up(): void
    {
        Schema::table('recommendation_outcomes', function (Blueprint $table) {
            $table->foreignUuid('hotel_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('booking_id')->nullable()->after('recommendation_id')->constrained()->nullOnDelete();
            $table->foreignUuid('stay_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();

            // Added nullable so existing rows can be backfilled, then tightened
            // to NOT NULL below. None of the three ever gets a default.
            $table->string('outcome')->nullable();
            $table->string('attribution_method')->nullable();
            $table->string('evidence_level', 4)->nullable();

            $table->string('channel')->nullable();   // whatsapp, face_to_face, phone
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // What was committed — NOT what was settled. Settlement lives on
            // the transaction rows linked to the booking.
            $table->decimal('expected_value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('minutes_to_outcome')->nullable();

            $table->text('decline_reason')->nullable();
            // The guest's own statement. When someone later disputes a
            // classification, the raw words settle it.
            $table->text('evidence_quote')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->json('context')->nullable();     // agent, window, matcher version

            // One live outcome per recommendation, upserted as its state
            // advances; the change history lives in event_log.
            $table->unique('recommendation_id');
            // No booking is ever credited to two recommendations.
            $table->unique('booking_id');
            $table->index(['attribution_method', 'outcome']);
        });

        DB::statement('
            UPDATE recommendation_outcomes
            SET hotel_id = recommendations.hotel_id
            FROM recommendations
            WHERE recommendations.id = recommendation_outcomes.recommendation_id
        ');

        // Pre-existing free-text rows are kept, not dropped. Their provenance
        // is unknown, so they are labelled NONE/L4 rather than STAFF/L1 —
        // claiming an observation nobody made is exactly the failure the
        // evidence scale exists to prevent. DELIVERED is the weakest outcome
        // that stays true: something was recorded, so the offer reached the
        // guest at some point.
        DB::table('recommendation_outcomes')->whereNull('outcome')->update([
            'outcome' => OutcomeType::DELIVERED->value,
            'attribution_method' => AttributionMethod::NONE->value,
            'evidence_level' => EvidenceLevel::L4->value,
        ]);

        foreach (['outcome', 'attribution_method', 'evidence_level', 'hotel_id'] as $column) {
            DB::statement("ALTER TABLE recommendation_outcomes ALTER COLUMN {$column} SET NOT NULL");
        }

        // The legacy free-text column no longer carries the meaning.
        DB::statement('ALTER TABLE recommendation_outcomes ALTER COLUMN type DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('recommendation_outcomes', function (Blueprint $table) {
            $table->dropUnique(['recommendation_id']);
            $table->dropUnique(['booking_id']);
            $table->dropIndex(['attribution_method', 'outcome']);
            $table->dropConstrainedForeignId('hotel_id');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('stay_id');
            $table->dropConstrainedForeignId('recorded_by_user_id');
            $table->dropColumn([
                'outcome', 'attribution_method', 'evidence_level', 'channel',
                'expected_value', 'currency', 'minutes_to_outcome',
                'decline_reason', 'evidence_quote', 'confidence', 'context',
            ]);
        });
    }
};
