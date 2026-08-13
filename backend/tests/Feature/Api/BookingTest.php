<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The authenticated shipment API plus the public estimator.
 *
 * Two themes here. First, isolation: a booking ULID is the public identifier, so
 * every test that could leak someone else's shipment is a test that the policy,
 * not the unguessable id, is doing the work (§4.5). Second, the timeline: the
 * customer sees the customer-visible slice of booking_events and nothing more
 * (§4.8), and a status change is only ever legal if BookingStatus says so.
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly the Booking interface in mobile/src/types/api.ts, minus the optional `events`. */
    private const CONTRACT_KEYS = [
        'ulid',
        'booking_number',
        'status',
        'service',
        'pickup_city',
        'pickup_state',
        'dropoff_city',
        'dropoff_state',
        'scheduled_pickup_date',
        'scheduled_delivery_date',
        'actual_pickup_at',
        'actual_delivery_at',
        'total_price',
        'amount_paid',
        'balance_due',
        'can_review',
    ];

    /** Exactly the BookingEvent interface in mobile/src/types/api.ts. */
    private const EVENT_CONTRACT_KEYS = [
        'id',
        'event_type',
        'to_status',
        'description',
        'lat',
        'lng',
        'occurred_at',
    ];

    public function test_the_catalog_and_booking_endpoints_are_registered_under_api_v1(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->methods()[0].' '
                // Parameter names and binding fields vary ({booking}, {booking:ulid});
                // this asserts the path shape, not how the agent named the segment.
                .preg_replace('/\{[^}]+\}/', '{p}', $route->uri()))
            ->all();

        foreach ([
            'GET api/v1/services',
            'GET api/v1/services/{p}',
            'GET api/v1/vehicle-types',
            'GET api/v1/locations',
            'GET api/v1/faqs',
            'GET api/v1/settings/public',
            'POST api/v1/contact-messages',
            'POST api/v1/quotes/estimate',
            'POST api/v1/quote-requests',
            'GET api/v1/quote-requests',
            'GET api/v1/quote-requests/{p}',
            'GET api/v1/bookings',
            'GET api/v1/bookings/{p}',
            'GET api/v1/bookings/{p}/events',
            'POST api/v1/bookings/{p}/cancel',
            'POST api/v1/device-tokens',
        ] as $expected) {
            $this->assertContains($expected, $registered, "routes/api.php does not register: {$expected}");
        }
    }

    public function test_a_user_sees_only_their_own_bookings(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $service = $this->service();

        $mine = $this->booking($user, ['service_id' => $service->id]);
        $alsoMine = $this->booking($user);
        $theirs = $this->booking($stranger);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/bookings')->assertOk();

        $numbers = collect($response->json('data'))->pluck('booking_number')->all();

        $this->assertEqualsCanonicalizing(
            [$mine->booking_number, $alsoMine->booking_number],
            $numbers
        );
        $response->assertJsonMissing(['booking_number' => $theirs->booking_number]);

        // Paginated<T>: a data array plus the four meta keys the client reads.
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $row = collect($response->json('data'))
            ->firstWhere('booking_number', $mine->booking_number);

        $this->assertEqualsCanonicalizing(self::CONTRACT_KEYS, array_keys($row));

        // Money is minor units plus a currency, never a formatted string (§4.4).
        $this->assertSame(['cents' => 129900, 'currency' => 'USD'], $row['total_price']);
        $this->assertSame(['cents' => 20000, 'currency' => 'USD'], $row['amount_paid']);
        $this->assertSame(['cents' => 109900, 'currency' => 'USD'], $row['balance_due']);
        $this->assertSame(['id' => $service->id, 'name' => $service->name, 'slug' => $service->slug], $row['service']);
        $this->assertFalse($row['can_review']);
    }

    public function test_a_guest_cannot_list_bookings(): void
    {
        $this->booking(User::factory()->create());

        $this->getJson('/api/v1/bookings')->assertUnauthorized();
    }

    public function test_a_strangers_booking_ulid_does_not_return_the_record(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $booking = $this->booking($owner);

        Sanctum::actingAs($intruder);

        foreach ([
            "/api/v1/bookings/{$booking->ulid}",
            "/api/v1/bookings/{$booking->ulid}/events",
        ] as $url) {
            $response = $this->getJson($url);

            $this->assertContains(
                $response->status(),
                [403, 404],
                "{$url} answered {$response->status()} instead of 403/404"
            );
            $response->assertDontSee($booking->booking_number);
        }

        // And the cancel action is not reachable either, whatever the ULID.
        $this->postJson("/api/v1/bookings/{$booking->ulid}/cancel")->assertForbidden();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_the_events_endpoint_hides_internal_dispatch_notes(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $booking->recordEvent('note', [
            'description' => 'Carrier confirmed for Tuesday.',
            'occurred_at' => now()->subDays(2),
        ]);
        $booking->recordEvent('note', [
            'description' => 'Carrier ghosting, rebooking with a second carrier.',
            'is_customer_visible' => false,
            'occurred_at' => now()->subDay(),
        ]);
        $booking->recordEvent('location_ping', [
            'lat' => 32.7767,
            'lng' => -96.7970,
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/bookings/{$booking->ulid}/events")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response->assertDontSee('ghosting');
        $this->assertEqualsCanonicalizing(self::EVENT_CONTRACT_KEYS, array_keys($response->json('data.0')));

        // Chronological by occurred_at, which is what the tracking screen renders.
        $this->assertSame(
            ['Carrier confirmed for Tuesday.', null],
            collect($response->json('data'))->pluck('description')->all()
        );

        // Coordinates are numbers, not the strings a decimal:7 cast hands back.
        $ping = $response->json('data.1');
        $this->assertSame('location_ping', $ping['event_type']);
        $this->assertSame(32.7767, $ping['lat']);
        $this->assertSame(-96.797, $ping['lng']);
        $this->assertNull($ping['to_status']);
    }

    public function test_cancelling_a_delivered_booking_is_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user, ['status' => BookingStatus::Delivered]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/bookings/{$booking->ulid}/cancel", [
            'reason' => 'Changed my mind.',
        ]);

        // The DomainException from BookingStatus::allowedNext() must arrive as a
        // readable business rule, not as a stack trace.
        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['status']]);
        $this->assertStringContainsString('Delivered', $response->json('message'));

        $booking->refresh();

        // An illegal transition writes nothing at all: no status, no reason, no event.
        $this->assertSame(BookingStatus::Delivered, $booking->status);
        $this->assertNull($booking->cancelled_at);
        $this->assertNull($booking->cancellation_reason);
        $this->assertSame(0, DB::table('booking_events')->where('booking_id', $booking->id)->count());
    }

    public function test_the_owner_can_cancel_a_confirmed_booking_and_the_timeline_records_it(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user, ['service_id' => $this->service()->id]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/bookings/{$booking->ulid}/cancel", ['reason' => 'Sold the car.'])
            ->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Cancelled->value)
            ->assertJsonPath('data.can_review', false);

        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('Sold the car.', $booking->cancellation_reason);
        $this->assertSame($user->id, $booking->cancelled_by);
        $this->assertNotNull($booking->cancelled_at);

        // §4.8: never a status UPDATE without an event INSERT.
        $event = DB::table('booking_events')->where('booking_id', $booking->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('status_change', $event->event_type);
        $this->assertSame(BookingStatus::Confirmed->value, $event->from_status);
        $this->assertSame(BookingStatus::Cancelled->value, $event->to_status);
        $this->assertStringContainsString('Sold the car.', $event->description);
    }

    public function test_the_estimate_endpoint_prices_a_known_input(): void
    {
        $service = $this->service([
            'base_price_cents' => 50000,
            'price_per_mile_cents' => 75,
            'min_price_cents' => 40000,
        ]);

        $sedan = $this->vehicleType('sedan', '1.000');
        $suv = $this->vehicleType('suv', '1.150');

        /**
         * §7, by hand:
         *   line       = max(40000, 50000 + 1200 x 75) = 140000
         *   multiplier = 1.000 + 1.150                 = 2.15
         *   inoperable = one car on a winch            = x 1.35
         *   total      = 140000 x 2.15 x 1.35          = 406350
         * Public endpoint, so no token on this request.
         */
        $this->postJson('/api/v1/quotes/estimate', [
            'service_id' => $service->id,
            'distance_miles' => 1200,
            'vehicles' => [
                ['vehicle_type_id' => $sedan->id, 'is_operable' => true],
                ['vehicle_type_id' => $suv->id, 'is_operable' => false],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.estimated_price.cents', 406350)
            ->assertJsonPath('data.estimated_price.currency', 'USD')
            ->assertJsonPath('data.distance_miles', 1200)
            ->assertJsonPath('data.vehicle_count', 2)
            // Never presented as a quote (§7).
            ->assertJsonPath('data.is_binding', false);
    }

    public function test_the_estimate_endpoint_applies_the_minimum_price_floor(): void
    {
        $service = $this->service([
            'base_price_cents' => 20000,
            'price_per_mile_cents' => 10,
            'min_price_cents' => 95000,
        ]);

        // 20000 + 100 x 10 = 21000, which the floor overrides before any
        // multiplier can dilute it.
        $this->postJson('/api/v1/quotes/estimate', [
            'service_id' => $service->id,
            'distance_miles' => 100,
            'vehicles' => [['vehicle_type_id' => $this->vehicleType('sedan', '1.000')->id, 'is_operable' => true]],
        ])
            ->assertOk()
            ->assertJsonPath('data.estimated_price.cents', 95000);
    }

    public function test_the_estimator_stub_falls_back_to_a_flat_distance_without_coordinates(): void
    {
        $service = $this->service([
            'base_price_cents' => 0,
            'price_per_mile_cents' => 100,
            'min_price_cents' => 0,
        ]);
        $sedan = $this->vehicleType('sedan', '1.000');

        // No distance and no lat/lng: the stub's flat 1000-mile national average.
        $this->postJson('/api/v1/quotes/estimate', [
            'service_id' => $service->id,
            'vehicles' => [['vehicle_type_id' => $sedan->id]],
        ])
            ->assertOk()
            ->assertJsonPath('data.distance_miles', 1000)
            ->assertJsonPath('data.estimated_price.cents', 100000);

        // Geocoded on both ends: great-circle Miami -> Chicago, ~1190 road-ish miles.
        $miles = $this->postJson('/api/v1/quotes/estimate', [
            'service_id' => $service->id,
            'vehicles' => [['vehicle_type_id' => $sedan->id]],
            'pickup' => ['lat' => 25.7617, 'lng' => -80.1918],
            'dropoff' => ['lat' => 41.8781, 'lng' => -87.6298],
        ])->assertOk()->json('data.distance_miles');

        $this->assertGreaterThan(1100, $miles);
        $this->assertLessThan(1300, $miles);
    }

    public function test_a_guest_can_submit_a_quote_request_and_the_server_owns_its_metadata(): void
    {
        $service = $this->service([
            'base_price_cents' => 50000,
            'price_per_mile_cents' => 75,
            'min_price_cents' => 40000,
        ]);
        $suv = $this->vehicleType('suv', '1.150');

        $response = $this->postJson('/api/v1/quote-requests', $this->quotePayload([
            'service_id' => $service->id,
            'vehicles' => [
                ['vehicle_type_id' => $suv->id, 'make' => 'Toyota', 'model' => '4Runner', 'vin' => '1hgcm82633a004352'],
                ['vehicle_type_id' => null, 'is_operable' => false],
            ],
        ]))->assertCreated();

        $response->assertJsonStructure([
            'data' => ['ulid', 'reference', 'status', 'estimated_price', 'distance_miles', 'vehicle_count', 'created_at'],
        ])
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.vehicle_count', 2);

        $quoteRequest = QuoteRequest::query()->sole();

        // §4.10: an anonymous lead has no owner until the e-mail is verified.
        $this->assertNull($quoteRequest->user_id);

        // Attribution and forensics come from the request, never the payload.
        $this->assertSame('mobile', $quoteRequest->source);
        $this->assertSame('127.0.0.1', $quoteRequest->ip_address);
        $this->assertSame('new', $quoteRequest->status->value);

        // The nested addressSchema payload is flattened onto the intake row (§4.3).
        $this->assertSame('Miami', $quoteRequest->pickup_city);
        $this->assertSame('Chicago', $quoteRequest->dropoff_city);
        $this->assertSame('US', $quoteRequest->pickup_country_code);
        $this->assertSame('residential', $quoteRequest->pickup_location_type);

        // Estimated, stored, and still not a quote: 1000 stub miles because the
        // lane was never geocoded -- which is also why distance_miles stays null
        // rather than passing a guess off as a cached routing result.
        $this->assertNull($quoteRequest->distance_miles);
        $this->assertSame(
            (int) round((50000 + 1000 * 75) * (1.15 + 1.0) * 1.35),
            (int) $quoteRequest->estimated_price_cents
        );

        $this->assertSame(2, $quoteRequest->vehicles()->count());
        // VINs are stored uppercase so a lookup by VIN matches.
        $this->assertSame('1HGCM82633A004352', $quoteRequest->vehicles()->orderBy('id')->first()->vin);
    }

    public function test_a_quote_request_for_the_same_place_twice_is_rejected(): void
    {
        // schemas.ts refines this client-side; the server is the real boundary.
        $this->postJson('/api/v1/quote-requests', $this->quotePayload([
            'dropoff' => ['city' => 'miami', 'postal_code' => '33139', 'country_code' => 'US'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('dropoff');

        $this->assertSame(0, QuoteRequest::query()->count());
    }

    public function test_a_quote_request_window_must_end_after_it_starts(): void
    {
        $this->postJson('/api/v1/quote-requests', $this->quotePayload([
            'pickup_date_earliest' => '2026-09-10',
            'pickup_date_latest' => '2026-09-01',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_date_latest');
    }

    public function test_a_quote_request_is_visible_only_to_the_customer_who_owns_it(): void
    {
        $owner = User::factory()->create();

        Sanctum::actingAs($owner);
        $ulid = $this->postJson('/api/v1/quote-requests', $this->quotePayload())
            ->assertCreated()
            ->json('data.ulid');

        $this->getJson('/api/v1/quote-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/quote-requests/{$ulid}")->assertForbidden();
        $this->getJson('/api/v1/quote-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    /** @param array<string, mixed> $overrides */
    private function service(array $overrides = []): Service
    {
        return Service::create(array_merge([
            'name' => 'Open Carrier Transport',
            'slug' => 'open-carrier-transport-'.DB::table('services')->count(),
            'base_price_cents' => 50000,
            'price_per_mile_cents' => 75,
            'min_price_cents' => 40000,
            'currency' => 'USD',
            'transit_days_min' => 3,
            'transit_days_max' => 7,
            'is_active' => true,
        ], $overrides));
    }

    private function vehicleType(string $slug, string $multiplier): VehicleType
    {
        return VehicleType::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'size_class' => 'standard',
            'price_multiplier' => $multiplier,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function booking(User $user, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'user_id' => $user->id,
            'status' => BookingStatus::Confirmed,
            'pickup_line1' => '100 Ocean Drive',
            'pickup_city' => 'Miami',
            'pickup_state' => 'FL',
            'dropoff_line1' => '900 N Michigan Ave',
            'dropoff_city' => 'Chicago',
            'dropoff_state' => 'IL',
            'total_price_cents' => 129900,
            'deposit_cents' => 20000,
            'amount_paid_cents' => 20000,
        ], $attributes));
    }

    /**
     * quoteRequestSchema's shape, as the client posts it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function quotePayload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => null,
            'contact_name' => 'Sarah Mendoza',
            'contact_email' => 'sarah@example.com',
            'contact_phone' => '+1 (555) 123-4567',
            'pickup' => ['city' => 'Miami', 'state' => 'FL', 'postal_code' => '33139', 'country_code' => 'US'],
            'dropoff' => ['city' => 'Chicago', 'state' => 'IL', 'postal_code' => '60611', 'country_code' => 'US'],
            'pickup_date_earliest' => '2026-09-01',
            'pickup_date_latest' => '2026-09-05',
            'dates_flexible' => true,
            'vehicles' => [['vehicle_type_id' => null, 'year' => 2021, 'make' => 'Honda', 'model' => 'Civic']],
            'additional_notes' => 'Gate code 4821.',
        ], $overrides);
    }
}
