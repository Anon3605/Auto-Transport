<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-table lead pipeline:
 *   quote_requests = what the CUSTOMER submitted (immutable intake record)
 *   quotes         = what the COMPANY offered back (versioned, expiring)
 * Keeping them apart preserves negotiation history and makes "what did we
 * actually quote on 3 March" answerable a year later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 24)->unique();          // QR-2026-0001842, shown to humans

            // Nullable: the quote form must work for logged-out visitors.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->default('new');       // new|reviewing|quoted|accepted|declined|expired|spam
            $table->string('contact_name', 160);
            $table->string('contact_email');
            $table->string('contact_phone', 32)->nullable();

            // Origin -- flattened, not a FK to addresses. See design doc §4.3.
            $table->string('pickup_line1')->nullable();
            $table->string('pickup_city', 120);
            $table->string('pickup_state', 120)->nullable();
            $table->string('pickup_postal_code', 24)->nullable();
            $table->char('pickup_country_code', 2)->default('US');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->string('pickup_location_type', 24)->default('residential');

            // Destination
            $table->string('dropoff_line1')->nullable();
            $table->string('dropoff_city', 120);
            $table->string('dropoff_state', 120)->nullable();
            $table->string('dropoff_postal_code', 24)->nullable();
            $table->char('dropoff_country_code', 2)->default('US');
            $table->decimal('dropoff_lat', 10, 7)->nullable();
            $table->decimal('dropoff_lng', 10, 7)->nullable();
            $table->string('dropoff_location_type', 24)->default('residential');

            // Date WINDOW, not a timestamp -- carriers commit to a range.
            $table->date('pickup_date_earliest');
            $table->date('pickup_date_latest')->nullable();
            $table->boolean('dates_flexible')->default(false);

            $table->unsignedTinyInteger('vehicle_count')->default(1); // denormalised child count
            $table->unsignedInteger('distance_miles')->nullable();    // cached routing result
            $table->unsignedBigInteger('estimated_price_cents')->nullable(); // instant auto-estimate
            $table->char('currency', 3)->default('USD');
            $table->text('additional_notes')->nullable();

            // Attribution + abuse forensics
            $table->string('source', 24)->default('web');       // web|mobile|phone|partner
            $table->json('utm')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedTinyInteger('spam_score')->default(0);

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Admin queue: "open leads, newest first"
            $table->index(['status', 'created_at'], 'qr_status_created_idx');
            $table->index(['assigned_to', 'status'], 'qr_assignee_status_idx');
            $table->index('contact_email');                     // claim guest leads on signup
            $table->index(['pickup_postal_code', 'dropoff_postal_code'], 'qr_lane_idx'); // lane analytics
        });

        // One request can cover several cars. Never model this as vehicle_2_make columns.
        Schema::create('quote_request_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('make', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('trim', 64)->nullable();
            $table->string('color', 48)->nullable();
            $table->string('vin', 17)->nullable();
            $table->boolean('is_operable')->default(true);      // biggest single price driver
            $table->boolean('is_modified')->default(false);     // lift kit / lowered
            $table->unsignedSmallInteger('length_in')->nullable();
            $table->unsignedInteger('weight_lb')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('quote_request_id');
        });

        // A priced offer. Re-quoting creates version 2, never an UPDATE.
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 24)->unique();          // Q-2026-0000731
            $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);

            $table->unsignedBigInteger('total_price_cents');
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedBigInteger('carrier_pay_cents')->nullable(); // internal margin data
            $table->unsignedBigInteger('broker_fee_cents')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->string('status', 24)->default('draft');     // draft|sent|viewed|accepted|declined|expired|superseded
            $table->timestamp('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['quote_request_id', 'version'], 'quotes_request_version_uq');
            $table->index(['status', 'valid_until'], 'quotes_status_expiry_idx'); // expiry sweeper job
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_request_vehicles');
        Schema::dropIfExists('quote_requests');
    }
};
