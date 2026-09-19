<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Written only by RecommendationDeliveryService. No backfill: nobody
     * knows whether an existing recommendation ever reached its guest, and
     * null says exactly that.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // When the guest was actually offered it. Null = never delivered
            // (or delivered before tracking began — see
            // pitching.delivery_tracking_since).
            $table->timestamp('delivered_at')->nullable()->after('recommended_at');
            $table->string('delivery_channel')->nullable()->after('delivered_at');   // App\Enums\DeliveryChannel

            $table->index(['hotel_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'delivered_at']);
            $table->dropColumn(['delivered_at', 'delivery_channel']);
        });
    }
};
