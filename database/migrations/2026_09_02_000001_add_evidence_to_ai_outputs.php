<?php

use App\Enums\EvidenceLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An AI-generated statement is a hypothesis until something verifies it,
     * so both tables default to L3. evidence_sources holds the ids of the
     * records the claim actually rests on.
     */
    public function up(): void
    {
        foreach (['ai_insights', 'recommendations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('evidence_level', 4)->default(EvidenceLevel::L3->value);
                $table->json('evidence_sources')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_insights', 'recommendations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['evidence_level', 'evidence_sources']);
            });
        }
    }
};
