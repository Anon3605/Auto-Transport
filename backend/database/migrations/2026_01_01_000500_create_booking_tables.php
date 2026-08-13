<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An accepted quote becomes a booking. Addresses and prices are SNAPSHOTTED
 * here -- editing a saved address next year must not rewrite last year's
 * shipment record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('booking_number', 24)->unique();     // BK-2026-000318

            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->default('pending_payment');
            // pending_payment|confirmed|assigned|picked_up|in_transit|delivered|cancelled

            // Fulfilment assignment
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();

            // --- Pickup snapshot -------------------------------------------
            $table->string('pickup_contact_name')->nullable();
            $table->string('pickup_contact_phone', 32)->nullable();
            $table->string('pickup_line1');
            $table->string('pickup_line2')->nullable();
            $table->string('pickup_city', 120);
            $table->string('pickup_state', 120)->nullable();
            $table->string('pickup_postal_code', 24)->nullable();
            $table->char('pickup_country_code', 2)->default('US');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            // --- Dropoff snapshot ------------------------------------------
            $table->string('dropoff_contact_name')->nullable();
            $table->string('dropoff_contact_phone', 32)->nullable();
            $table->string('dropoff_line1');
            $table->string('dropoff_line2')->nullable();
            $table->string('dropoff_city', 120);
            $table->string('dropoff_state', 120)->nullable();
            $table->string('dropoff_postal_code', 24)->nullable();
            $table->char('dropoff_country_code', 2)->default('US');
            $table->decimal('dropoff_lat', 10, 7)->nullable();
            $table->decimal('dropoff_lng', 10, 7)->nullable();

            // Planned vs actual -- the gap is your on-time KPI.
            $table->date('scheduled_pickup_date')->nullable();
            $table->date('scheduled_delivery_date')->nullable();
            $table->timestamp('actual_pickup_at')->nullable();
            $table->timestamp('actual_delivery_at')->nullable();

            $table->unsignedInteger('distance_miles')->nullable();
            $table->unsignedBigInteger('total_price_cents');
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            $table->text('special_instructions')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            // Set when the "leave a review" invite fires; guards duplicate prompts.
            $table->timestamp('review_requested_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status'], 'bookings_user_status_idx');   // "my shipments" in RN app
            $table->index(['status', 'scheduled_pickup_date'], 'bookings_dispatch_idx');
            $table->index(['carrier_id', 'status'], 'bookings_carrier_idx');
            $table->index(['driver_id', 'status'], 'bookings_driver_idx');
        });

        Schema::create('booking_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('make', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('color', 48)->nullable();
            $table->string('vin', 17)->nullable();
            $table->boolean('is_operable')->default(true);

            // Bill of lading / condition report data
            $table->unsignedInteger('pickup_odometer')->nullable();
            $table->unsignedInteger('delivery_odometer')->nullable();
            $table->json('pickup_damage_map')->nullable();      // [{x,y,code,note}] over a car diagram
            $table->json('delivery_damage_map')->nullable();
            $table->text('pickup_condition_notes')->nullable();
            $table->text('delivery_condition_notes')->nullable();
            $table->timestamps();

            $table->index('booking_id');
            $table->index('vin');
        });

        // Append-only timeline. Drives the tracking screen and is your audit trail
        // for "when did the status change and who changed it".
        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);                   // status_change|note|location_ping|document|payment
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_customer_visible')->default(true); // hide internal dispatch chatter
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['booking_id', 'occurred_at'], 'booking_events_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('booking_vehicles');
        Schema::dropIfExists('bookings');
    }
};
