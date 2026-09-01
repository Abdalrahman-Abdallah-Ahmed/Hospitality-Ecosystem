<?php

use App\Enums\EvidenceLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail. Append-only, same discipline as the transaction
     * ledger: no soft deletes, no update path. Every create/update/delete of
     * an audited record lands here with who did it, when, and what changed.
     * Kept in its own table with no cascade so an entry outlives its subject.
     */
    public function up(): void
    {
        Schema::create('event_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event_type');              // 'reservation.updated', 'transaction.reversed'
            $table->uuidMorphs('subject');             // subject_type + subject_id (+ index)
            $table->nullableUuidMorphs('actor');       // a User, or null for system / AI

            $table->string('actor_kind');              // App\Enums\ActorKind
            $table->json('changes')->nullable();       // { field: { from, to } }
            $table->json('context')->nullable();       // ip, route, queue flag
            $table->string('evidence_level', 4)->default(EvidenceLevel::L1->value);
            $table->text('reason')->nullable();

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['hotel_id', 'event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_log');
    }
};
