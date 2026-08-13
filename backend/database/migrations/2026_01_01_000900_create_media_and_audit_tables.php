<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting: file attachments (inspection photos, BOLs, review images)
 * and the admin audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('collection', 64)->default('default');
            // pickup_photos | delivery_photos | bol | insurance_cert | review_photos
            $table->string('disk', 32)->default('s3');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->nullable(); // dedupe + tamper evidence
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->decimal('captured_lat', 10, 7)->nullable();  // EXIF, for pickup photo provenance
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->boolean('is_public')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['model_type', 'model_id', 'collection'], 'media_model_collection_idx');
            $table->index('checksum_sha256');
        });

        // spatie/activitylog-compatible
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject_morph_idx');
            $table->nullableMorphs('causer', 'causer_morph_idx');
            $table->string('event')->nullable();       // created|updated|deleted
            $table->json('properties')->nullable();    // {old:{...}, attributes:{...}}
            $table->uuid('batch_uuid')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('log_name');
            $table->index(['created_at'], 'activity_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('media');
    }
};
