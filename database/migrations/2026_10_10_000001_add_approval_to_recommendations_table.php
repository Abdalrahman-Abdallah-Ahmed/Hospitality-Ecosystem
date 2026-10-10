<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every recommendation is reviewed by an approver before a guest may hear
     * it (SPEC-071, D9). `pending` ("waiting to be offered") is retired: rows
     * still waiting were never reviewed, so they become pending_approval.
     * Every other status keeps its value.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // App\Enums\RecommendationSource — where it was generated.
            $table->string('source')->nullable()->after('status');

            // Written only by RecommendationApprovalService.
            $table->foreignUuid('reviewed_by_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->string('review_reason', 500)->nullable()->after('reviewed_at');

            $table->index(['hotel_id', 'status', 'recommended_at']);
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('status')->default('pending_approval')->change();
        });

        DB::table('recommendations')->where('status', 'pending')->update(['status' => 'pending_approval']);
        DB::table('recommendations')->whereNull('source')->update(['source' => 'legacy']);
    }

    public function down(): void
    {
        DB::table('recommendations')->whereIn('status', ['pending_approval', 'approved'])->update(['status' => 'pending']);
        DB::table('recommendations')->where('status', 'rejected_by_admin')->update(['status' => 'cancelled']);

        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'status', 'recommended_at']);
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['source', 'reviewed_at', 'review_reason']);
        });
    }
};
