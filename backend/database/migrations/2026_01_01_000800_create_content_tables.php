<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS + SEO + inbound messages. seo_meta is polymorphic so one admin UI
 * component serves pages, services and locations alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 180)->unique();          // 'home', 'about', 'contact'
            $table->string('title', 200);
            $table->string('excerpt', 320)->nullable();
            $table->longText('body')->nullable();
            $table->json('blocks')->nullable();             // page-builder sections
            $table->string('template', 64)->default('default');
            $table->string('status', 16)->default('draft'); // draft|published
            $table->boolean('is_system')->default(false);   // system pages cannot be deleted
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
        });

        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->string('metable_type');
            $table->unsignedBigInteger('metable_id');
            $table->string('meta_title', 70)->nullable();       // SERP truncation limits
            $table->string('meta_description', 160)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('robots', 64)->default('index,follow');
            $table->string('og_title', 95)->nullable();
            $table->string('og_description', 200)->nullable();
            $table->string('og_image_path')->nullable();
            $table->string('twitter_card', 32)->default('summary_large_image');
            $table->json('schema_json')->nullable();            // JSON-LD: LocalBusiness, Service, AggregateRating
            $table->decimal('sitemap_priority', 2, 1)->default(0.5);
            $table->string('sitemap_changefreq', 16)->default('monthly');
            $table->timestamps();

            $table->unique(['metable_type', 'metable_id'], 'seo_meta_metable_uq');
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64)->nullable();
            $table->string('question', 320);
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'category', 'sort_order'], 'faqs_listing_idx');
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message');
            $table->string('status', 24)->default('new');   // new|read|replied|spam|archived
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reply_body')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->unsignedTinyInteger('spam_score')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at'], 'contact_status_created_idx');
            $table->index('email');
        });

        // Typed key/value site config: company phone, map centre, business hours,
        // quote-expiry days, deposit percentage.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64)->default('general'); // general|contact|seo|pricing|integrations
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string');   // string|int|bool|json|encrypted
            $table->boolean('is_public')->default(false);    // exposed to the RN app / frontend?
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key']);
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('pages');
    }
};
