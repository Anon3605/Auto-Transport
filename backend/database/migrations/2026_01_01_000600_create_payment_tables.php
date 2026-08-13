<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial ledger. Rows are append-only: a refund is a NEW negative-intent
 * row, never an UPDATE of the original capture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 24);                 // deposit|balance|full|refund|chargeback
            $table->string('gateway', 32);              // stripe|sslcommerz|bkash|paypal|manual
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_payload')->nullable();     // strip PAN/CVV before persisting
            $table->string('idempotency_key', 64)->unique(); // blocks double-charge on retry

            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('status', 24)->default('pending'); // pending|authorized|captured|failed|refunded|disputed

            $table->string('card_brand', 24)->nullable();
            $table->string('card_last4', 4)->nullable();     // the ONLY card data that touches this DB
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('refunds_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // manual entries
            $table->timestamps();

            $table->index(['booking_id', 'status'], 'payments_booking_status_idx');
            $table->index(['gateway', 'gateway_reference'], 'payments_gateway_ref_idx'); // webhook lookup
            $table->index(['status', 'created_at']);
        });

        // Raw webhook receipts, stored before processing. Lets you replay a
        // failed handler without asking the gateway to resend.
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('event_id')->nullable();
            $table->string('event_type', 80)->nullable();
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);   // replay protection
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payments');
    }
};
