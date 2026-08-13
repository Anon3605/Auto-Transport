<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private VehicleType $vehicleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, CatalogSeeder::class, ContentSeeder::class]);

        $this->service = Service::query()->where('is_active', true)->firstOrFail();
        $this->vehicleType = VehicleType::query()->firstOrFail();
    }

    public function test_a_customer_can_book_a_service_and_it_appears_in_their_shipments(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/bookings', $this->payload())->assertCreated();

        $ulid = $response->json('data.ulid');

        // Opens awaiting payment, not confirmed: the price is the automated
        // estimate and a human still gates it.
        $response->assertJsonPath('data.status', BookingStatus::PendingPayment->value);

        $booking = Booking::query()->where('ulid', $ulid)->firstOrFail();
        $this->assertSame($user->id, $booking->user_id);
        $this->assertGreaterThan(0, $booking->total_price_cents);

        // The whole point of the request: it shows up in "my shipments".
        $this->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.ulid', $ulid);
    }

    /**
     * §4.1: a booking without its intake and offer rows cannot answer "what were
     * they quoted, and when". bookings.quote_id is nullable, so nothing at the
     * database level would have caught this being skipped.
     */
    public function test_booking_writes_the_full_quote_request_and_quote_chain(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $ulid = $this->postJson('/api/v1/bookings', $this->payload())
            ->assertCreated()
            ->json('data.ulid');

        $booking = Booking::query()->where('ulid', $ulid)->firstOrFail();

        $this->assertNotNull($booking->quote_request_id, 'The intake record was not written.');
        $this->assertNotNull($booking->quote_id, 'The accepted quote was not written.');

        $this->assertSame('accepted', $booking->quoteRequest->status->value ?? $booking->quoteRequest->status);
        $this->assertSame(1, (int) $booking->quote->version);

        // The price the customer sees must equal the offer on record.
        $this->assertSame(
            (int) $booking->quote->total_price_cents,
            (int) $booking->total_price_cents,
            'The booking price drifted from the quote it was created against.'
        );
    }

    /** §4.8: a timeline with no origin row is useless as an audit trail. */
    public function test_booking_opens_its_timeline_with_an_event(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $ulid = $this->postJson('/api/v1/bookings', $this->payload())->assertCreated()->json('data.ulid');

        $this->getJson("/api/v1/bookings/{$ulid}/events")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'booked');
    }

    public function test_vehicles_are_recorded_against_the_booking(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $ulid = $this->postJson('/api/v1/bookings', $this->payload([
            'vehicles' => [
                ['vehicle_type_id' => $this->vehicleType->id, 'year' => 2019, 'make' => 'Subaru', 'model' => 'Outback', 'is_operable' => true],
                ['vehicle_type_id' => $this->vehicleType->id, 'year' => 1972, 'make' => 'Datsun', 'model' => '240Z', 'is_operable' => false],
            ],
        ]))->assertCreated()->json('data.ulid');

        $booking = Booking::query()->where('ulid', $ulid)->firstOrFail();

        $this->assertCount(2, $booking->vehicles);
        $this->assertSame(2, $booking->quoteRequest->vehicle_count);
    }

    /** An inoperable car needs a winch, which §7 prices at +35%. */
    public function test_an_inoperable_vehicle_costs_more_than_a_running_one(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $running = $this->postJson('/api/v1/bookings', $this->payload())->assertCreated();

        $broken = $this->postJson('/api/v1/bookings', $this->payload([
            'vehicles' => [['vehicle_type_id' => $this->vehicleType->id, 'is_operable' => false]],
        ]))->assertCreated();

        $this->assertGreaterThan(
            $running->json('data.total_price.cents'),
            $broken->json('data.total_price.cents'),
        );
    }

    public function test_a_guest_cannot_book(): void
    {
        $this->postJson('/api/v1/bookings', $this->payload())->assertUnauthorized();
    }

    /**
     * A suspended account is one operations stopped doing business with; letting
     * it open a shipment would quietly reverse that.
     */
    public function test_a_suspended_user_cannot_book(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'suspended']));

        $this->postJson('/api/v1/bookings', $this->payload())->assertForbidden();
    }

    public function test_a_pickup_date_in_the_past_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/v1/bookings', $this->payload([
            'pickup_date_earliest' => now()->subDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('pickup_date_earliest');
    }

    public function test_a_booking_needs_at_least_one_vehicle_and_both_cities(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/v1/bookings', $this->payload(['vehicles' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('vehicles');

        $payload = $this->payload();
        $payload['dropoff']['city'] = '';

        $this->postJson('/api/v1/bookings', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('dropoff.city');
    }

    public function test_an_unknown_service_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/v1/bookings', $this->payload(['service_slug' => 'no-such-service']))
            ->assertStatus(422)->assertJsonValidationErrors('service_slug');
    }

    /**
     * A malformed VIN is worth catching at the boundary: it is transcribed off a
     * windscreen and ends up on the bill of lading.
     */
    public function test_a_malformed_vin_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/v1/bookings', $this->payload([
            'vehicles' => [['vehicle_type_id' => $this->vehicleType->id, 'vin' => 'IOQ00000000000000']],
        ]))->assertStatus(422)->assertJsonValidationErrors('vehicles.0.vin');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_slug' => $this->service->slug,
            'pickup' => ['line1' => '901 E 6th St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'dropoff' => ['line1' => '1701 Wynkoop St', 'city' => 'Denver', 'state' => 'CO', 'postal_code' => '80202', 'country_code' => 'US'],
            'pickup_date_earliest' => now()->addWeek()->toDateString(),
            'dates_flexible' => true,
            'vehicles' => [
                ['vehicle_type_id' => $this->vehicleType->id, 'year' => 2019, 'make' => 'Subaru', 'model' => 'Outback', 'is_operable' => true],
            ],
        ], $overrides);
    }
}
