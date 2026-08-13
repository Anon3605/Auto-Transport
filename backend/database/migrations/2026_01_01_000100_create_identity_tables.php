<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends Laravel's default users table and adds the access-control surface.
 * Run AFTER the framework default 0001_01_01_000000_create_users_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Augment the framework users table -------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->after('id')->unique();          // public identifier for API
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('phone_normalized', 32)->nullable()->after('phone'); // E.164, indexed lookup
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 24)->default('active');      // active|suspended|pending
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->softDeletes();

            $table->index('phone_normalized');
            $table->index(['status', 'created_at'], 'users_status_created_idx');
        });

        // --- RBAC (schema-compatible with spatie/laravel-permission) ---------
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->string('group')->nullable();   // groups checkboxes in the admin UI
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_idx');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_idx');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
        });

        // --- Saved addresses (reusable; NOT the source of truth for bookings) -
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 64)->nullable();          // "Home", "Dealership"
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->char('country_code', 2)->default('US');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('location_type', 24)->default('residential'); // residential|business|terminal|auction|dealer|port
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_default']);
            $table->index(['postal_code', 'country_code']);
        });

        // --- Push notification targets for the React Native app --------------
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('token_hash', 64)->unique();   // sha256(token): unique index without a 512-byte key
            $table->string('platform', 16);               // ios|android
            $table->string('provider', 16)->default('expo'); // expo|fcm|apns
            $table->string('device_name')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_created_idx');
            $table->dropIndex(['phone_normalized']);
            $table->dropColumn([
                'ulid', 'phone', 'phone_normalized', 'phone_verified_at', 'avatar_path',
                'locale', 'timezone', 'status', 'last_login_at', 'last_login_ip',
                'failed_login_count', 'locked_until', 'two_factor_secret',
                'two_factor_recovery_codes', 'two_factor_confirmed_at', 'deleted_at',
            ]);
        });
    }
};
