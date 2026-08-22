<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Carrier;
use App\Models\User;
use App\Models\Service;
use App\Models\VehicleType;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTER = '/api/v1/auth/register';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_driver_can_register_with_their_licence_details(): void
    {
        $response = $this->postJson(self::REGISTER, $this->driverPayload())->assertCreated();

        $user = User::query()->where('email', 'marcus@example.com')->sole();

        $this->assertTrue($user->hasRole(UserRole::Driver->value));
        $this->assertFalse($user->hasRole(UserRole::Customer->value));

        $profile = $user->driverProfile;
        $this->assertNotNull($profile, 'A driver registration must create a driver profile.');
        $this->assertSame('D1234567', $profile->license_number);
        $this->assertSame('TX', $profile->license_state);
        $this->assertSame('A', $profile->cdl_class);

        // Signed in immediately, like any registration.
        $this->assertNotEmpty($response->json('token'));
    }

    /**
     * The vetting gate. Anyone can type "driver" into a form, so the account
     * exists but must be inert until a human checks the licence.
     */
    public function test_a_self_registered_driver_is_pending_and_unemployed(): void
    {
        $this->postJson(self::REGISTER, $this->driverPayload())->assertCreated();

        $user = User::query()->where('email', 'marcus@example.com')->sole();

        $this->assertSame('pending', $user->status);
        $this->assertNull($user->driverProfile->carrier_id, 'A new driver must not arrive pre-employed.');
        $this->assertFalse((bool) $user->driverProfile->is_available);
    }

    /** The API has to let the app distinguish "no work yet" from "not approved". */
    public function test_the_pending_status_is_visible_to_the_client(): void
    {
        $token = $this->postJson(self::REGISTER, $this->driverPayload())->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonPath('user.is_active', false);
    }

    /**
     * The second half of the gate, independent of status: AssignBookingRequest
     * refuses a driver who does not work for the chosen carrier, so an unvetted
     * driver is unassignable by construction.
     */
    public function test_a_pending_driver_cannot_be_assigned_work(): void
    {
        $this->postJson(self::REGISTER, $this->driverPayload())->assertCreated();
        $driver = User::query()->where('email', 'marcus@example.com')->sole();

        $carrier = Carrier::query()->create(['company_name' => 'Lone Star', 'status' => 'active']);
        $booking = Booking::factory()->for(User::factory()->create(['status' => 'active']))->create();

        $admin = User::role('super-admin')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.bookings.assign', $booking), [
            'carrier_id' => $carrier->id,
            'driver_id' => $driver->id,
        ])->assertSessionHasErrors('driver_id');

        $this->assertNull($booking->refresh()->driver_id);
    }

    /**
     * A pending account must not be able to transact as a customer either.
     *
     * The payload is deliberately VALID: validation runs before the policy, so an
     * empty body would 422 and prove nothing about authorization.
     */
    public function test_a_pending_driver_cannot_book_a_shipment(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->postJson(self::REGISTER, $this->driverPayload())->assertCreated();
        $driver = User::query()->where('email', 'marcus@example.com')->sole();

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/bookings', [
            'service_slug' => Service::query()->where('is_active', true)->firstOrFail()->slug,
            'pickup' => ['line1' => '1 A St', 'city' => 'Austin', 'state' => 'TX', 'country_code' => 'US'],
            'dropoff' => ['line1' => '2 B St', 'city' => 'Denver', 'state' => 'CO', 'country_code' => 'US'],
            'pickup_date_earliest' => now()->addWeek()->toDateString(),
            'vehicles' => [['vehicle_type_id' => VehicleType::query()->firstOrFail()->id, 'is_operable' => true]],
        ])->assertForbidden();
    }

    // --- privilege escalation -------------------------------------------------

    /**
     * The whole reason account_type is a whitelist. If this ever starts passing,
     * anyone can make themselves an administrator from the public form.
     */
    public function test_a_staff_role_cannot_be_self_assigned(): void
    {
        foreach (['super-admin', 'admin', 'dispatcher', 'support'] as $role) {
            $this->postJson(self::REGISTER, $this->driverPayload([
                'account_type' => $role,
                'email' => "escalate-{$role}@example.com",
            ]))->assertStatus(422)->assertJsonValidationErrors('account_type');

            $this->assertSame(0, User::query()->where('email', "escalate-{$role}@example.com")->count());
        }
    }

    /** A roles array in the body must be ignored, not honoured. */
    public function test_a_roles_array_in_the_body_is_ignored(): void
    {
        $this->postJson(self::REGISTER, $this->customerPayload([
            'roles' => ['super-admin'],
        ]))->assertCreated();

        $user = User::query()->where('email', 'dana@example.com')->sole();

        $this->assertTrue($user->hasRole(UserRole::Customer->value));
        $this->assertFalse($user->hasRole('super-admin'));
    }

    // --- validation -----------------------------------------------------------

    public function test_a_driver_must_supply_licence_details(): void
    {
        $payload = $this->driverPayload();
        unset($payload['license_number'], $payload['license_state'], $payload['license_expires_at'], $payload['cdl_class']);

        $this->postJson(self::REGISTER, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['license_number', 'license_state', 'license_expires_at', 'cdl_class']);
    }

    /** An expired licence is not a typo to accept and sort out later. */
    public function test_an_expired_licence_is_rejected(): void
    {
        $this->postJson(self::REGISTER, $this->driverPayload([
            'license_expires_at' => now()->subDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('license_expires_at');
    }

    public function test_a_driver_must_supply_a_phone_number(): void
    {
        $payload = $this->driverPayload();
        unset($payload['phone']);

        $this->postJson(self::REGISTER, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    /** A customer form must not start demanding a CDL class. */
    public function test_a_customer_registration_needs_none_of_the_driver_fields(): void
    {
        $this->postJson(self::REGISTER, $this->customerPayload())->assertCreated();

        $user = User::query()->where('email', 'dana@example.com')->sole();

        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasRole(UserRole::Customer->value));
        $this->assertNull($user->driverProfile);
    }

    /** Omitting account_type entirely keeps the original contract working. */
    public function test_account_type_defaults_to_customer(): void
    {
        $payload = $this->customerPayload();
        unset($payload['account_type']);

        $this->postJson(self::REGISTER, $payload)->assertCreated();

        $this->assertTrue(
            User::query()->where('email', 'dana@example.com')->sole()->hasRole(UserRole::Customer->value)
        );
    }

    public function test_an_unknown_licence_class_is_rejected(): void
    {
        $this->postJson(self::REGISTER, $this->driverPayload(['cdl_class' => 'class a']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cdl_class');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function driverPayload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'driver',
            'name' => 'Marcus Hale',
            'email' => 'marcus@example.com',
            'password' => 'correct-horse-7',
            'password_confirmation' => 'correct-horse-7',
            'phone' => '555 123 4567',
            'license_number' => 'D1234567',
            'license_state' => 'TX',
            'license_expires_at' => now()->addYears(2)->toDateString(),
            'cdl_class' => 'A',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'customer',
            'name' => 'Dana Reyes',
            'email' => 'dana@example.com',
            'password' => 'correct-horse-7',
            'password_confirmation' => 'correct-horse-7',
        ], $overrides);
    }
}
