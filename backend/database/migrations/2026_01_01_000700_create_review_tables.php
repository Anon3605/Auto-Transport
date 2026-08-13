<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review integrity rests on three constraints:
 *   1. booking_id is UNIQUE      -> one review per shipment, enforced by the DB
 *   2. booking_id is NOT NULL    -> no review without a real transaction
 *   3. status defaults to pending-> nothing is public until moderated
 * Application layer adds: booking.status must be 'delivered' and
 * booking.user_id must equal review.user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Copied at write time so a review survives service/carrier changes
            // and so aggregate queries never need a 3-table join.
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('rating_overall');            // 1..5, app-validated
            $table->unsignedTinyInteger('rating_communication')->nullable();
            $table->unsignedTinyInteger('rating_timeliness')->nullable();
            $table->unsignedTinyInteger('rating_condition')->nullable(); // car arrived as sent?
            $table->unsignedTinyInteger('rating_value')->nullable();

            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();

            $table->string('status', 24)->default('pending');         // pending|approved|rejected
            $table->boolean('is_verified')->default(true);            // derived from booking linkage
            $table->boolean('is_featured')->default(false);           // homepage testimonial slot
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            // Owner response, kept inline -- one reply per review is the norm.
            $table->text('admin_reply')->nullable();
            $table->timestamp('admin_replied_at')->nullable();
            $table->foreignId('admin_replied_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('helpful_count')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at'], 'reviews_moderation_queue_idx');
            $table->index(['service_id', 'status', 'rating_overall'], 'reviews_service_agg_idx');
            $table->index(['carrier_id', 'status'], 'reviews_carrier_idx');
            $table->index(['is_featured', 'status'], 'reviews_featured_idx');
        });

        Schema::create('review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_helpful')->default(true);
            $table->timestamps();

            $table->unique(['review_id', 'user_id']);   // one vote per user per review
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_votes');
        Schema::dropIfExists('reviews');
    }
};
