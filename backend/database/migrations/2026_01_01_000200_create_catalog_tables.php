<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-facing catalog: what the company sells, what can be shipped,
 * and where it operates (drives the Services page + Google Maps embed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();            // /services/enclosed-auto-transport
            $table->string('short_description', 320)->nullable(); // card copy + meta fallback
            $table->longText('description')->nullable();          // rich body
            $table->string('icon', 64)->nullable();
            $table->string('hero_image_path')->nullable();

            // Pricing inputs for the quote estimator. Integer minor units only.
            $table->unsignedBigInteger('base_price_cents')->default(0);
            $table->unsignedBigInteger('price_per_mile_cents')->default(0);
            $table->unsignedBigInteger('min_price_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            $table->unsignedSmallInteger('transit_days_min')->nullable();
            $table->unsignedSmallInteger('transit_days_max')->nullable();

            // Denormalised review aggregates (see reviews migration for rebuild strategy)
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order'], 'services_active_sort_idx');
            $table->index(['is_featured', 'is_active'], 'services_featured_idx');
        });

        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);                    // Sedan, SUV, Pickup, Motorcycle, RV
            $table->string('slug', 140)->unique();
            $table->string('size_class', 32);               // compact|standard|large|oversize|moto
            $table->decimal('price_multiplier', 5, 3)->default(1.000); // applied to service base
            $table->unsignedSmallInteger('default_length_in')->nullable();
            $table->unsignedInteger('default_weight_lb')->nullable();
            $table->string('icon', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Terminals / offices / coverage points. Powers terminal-to-terminal
        // service AND the Google Maps embed on the Contact page.
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('type', 24)->default('terminal'); // terminal|office|port|service_area
            $table->string('line1')->nullable();
            $table->string('line2')->nullable();
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->char('country_code', 2)->default('US');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->json('hours')->nullable();               // {"mon":["08:00","18:00"], ...}
            $table->boolean('is_primary')->default(false);   // the one embedded on Contact
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'type']);
            $table->index(['lat', 'lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('vehicle_types');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
