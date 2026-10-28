<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_email_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hotel_id');
            $table->uuid('reservation_id');
            $table->string('request_key', 100);
            $table->string('recipient');
            $table->uuid('voucher_template_id')->nullable();
            $table->string('language', 2);
            $table->boolean('show_total')->default(false);
            $table->jsonb('overrides')->nullable();
            $table->text('staff_note')->nullable();
            $table->string('status')->default('queued');
            $table->string('status_reason')->nullable();
            $table->jsonb('unplaced')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            // Set just before the mail is handed to a mailer that cannot drop
            // duplicates; a retry that finds it set does not send again.
            $table->timestampTz('send_started_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->onDelete('cascade');
            $table->foreign('reservation_id')->references('id')->on('reservations')->onDelete('cascade');
            $table->foreign('voucher_template_id')->references('id')->on('voucher_templates')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();

            // One request per client key (FR-027); a repeat returns the original.
            $table->unique(['hotel_id', 'reservation_id', 'request_key']);
            // Serves the rolling daily cap and the send history list.
            $table->index(['reservation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_email_requests');
    }
};
