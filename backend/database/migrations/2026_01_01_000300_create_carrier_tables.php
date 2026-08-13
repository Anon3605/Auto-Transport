<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply side. A broker subcontracts to carriers; an asset-based operator
 * owns trucks directly. This schema supports both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('company_name', 190);
            $table->string('mc_number', 32)->nullable()->unique();  // FMCSA motor carrier no.
            $table->string('dot_number', 32)->nullable()->unique();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_policy_no')->nullable();
            $table->date('insurance_expires_at')->nullable();
            $table->unsignedBigInteger('cargo_coverage_cents')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->string('status', 24)->default('pending'); // pending|approved|suspended
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'insurance_expires_at'], 'carriers_status_ins_idx');
        });

        // Driver-specific attributes hang off a user row rather than duplicating identity.
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('license_number', 64)->nullable();
            $table->string('license_state', 64)->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('cdl_class', 8)->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('is_available')->default(true);
            $table->decimal('last_lat', 10, 7)->nullable();   // last reported position
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();

            $table->index(['carrier_id', 'is_available']);
        });

        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('unit_number', 64);
            $table->string('plate', 32)->nullable();
            $table->string('trailer_type', 24)->default('open'); // open|enclosed|flatbed
            $table->unsignedTinyInteger('capacity_vehicles')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['carrier_id', 'unit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trucks');
        Schema::dropIfExists('driver_profiles');
        Schema::dropIfExists('carriers');
    }
};
