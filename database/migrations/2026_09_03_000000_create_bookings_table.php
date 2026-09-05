<?php

use App\Enums\BookingStatus;
use App\Enums\EvidenceLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A commitment: a slot is held and the guest is expected.
     *
     * This is the event the recommendation agent is actually trying to cause,
     * and it is deliberately separate from settlement. A guest may eat on
     * Thursday and pay at checkout on Sunday — or, on all-inclusive, never.
     * Money is a different fact about a different process, recorded in the
     * transaction ledger and linked back here only by direct reference.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();
            // Nullable on purpose: a guest can book something that is not in
            // the activity catalogue yet. item_name is the fallback, and the
            // catalogue catches up later.
            $table->foreignUuid('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('recommendation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 8)->unique();   // 'DCB-4K2P' — human-speakable
            $table->string('item_name');                // stored even when activity_id is null
            $table->string('status')->default(BookingStatus::PENDING->value);

            // When the guest is expected.
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedInteger('pax')->default(1);

            // Commercial expectation — NOT settlement.
            $table->string('charge_model');             // App\Enums\ChargeModel
            $table->decimal('expected_value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // How it came to exist.
            $table->string('origin');                   // App\Enums\BookingOrigin
            $table->string('channel')->nullable();      // whatsapp, face_to_face, phone, desk
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('realised_at')->nullable();   // guest actually attended
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->string('evidence_level', 4)->default(EvidenceLevel::L1->value);
            $table->json('context')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'scheduled_for']);
            $table->index(['hotel_id', 'status']);
            $table->index('recommendation_id');
            $table->index(['guest_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
