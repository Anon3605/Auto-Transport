<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Carrier;
use App\Models\DriverProfile;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Service;
use App\Models\Truck;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Believable sample traffic: a customer with shipment history, one carrier and
 * driver behind it, and a review set that leaves the moderation queue non-empty.
 *
 * NOT reference data. This seeder refuses to run in production -- fabricated
 * bookings and reviews in a live database are indistinguishable from real ones a
 * week later, and the reviews would be visible to customers.
 */
class DemoDataSeeder extends Seeder
{
    private const CUSTOMER_EMAIL = 'customer@autotransport.test';

    private const DRIVER_EMAIL = 'driver@autotransport.test';

    /** VINs are ISO 3779 shaped -- 17 characters, no I, O or Q. */
    private const VEHICLES = [
        ['type' => 'sedan', 'year' => 2019, 'make' => 'Honda', 'model' => 'Accord', 'color' => 'Silver', 'vin' => '1HGCV1F34LA123456'],
        ['type' => 'suv', 'year' => 2021, 'make' => 'Toyota', 'model' => 'Highlander', 'color' => 'Blue', 'vin' => '5TDZA23C13S012345'],
        ['type' => 'coupe', 'year' => 2016, 'make' => 'BMW', 'model' => '335i', 'color' => 'Black', 'vin' => 'WBA3A5C55DF123456'],
        ['type' => 'pickup-truck', 'year' => 2020, 'make' => 'Ford', 'model' => 'F-150', 'color' => 'White', 'vin' => '1FTFW1ET5DFA12345'],
        ['type' => 'motorcycle', 'year' => 2018, 'make' => 'Ducati', 'model' => 'Monster 821', 'color' => 'Red', 'vin' => 'JM1BL1UF3A1234567'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DemoDataSeeder skipped: it will not write fake bookings or reviews to production.');

            return;
        }

        $customer = $this->demoCustomer();

        // Re-running would otherwise stack a second set of shipments onto the same
        // account. Reference data is idempotent by matching on natural keys; demo
        // traffic has no natural key, so the whole block is skipped instead.
        if ($customer->bookings()->exists()) {
            $this->command?->info('Demo data already present -- skipping DemoDataSeeder.');

            return;
        }

        $moderator = $this->moderator();
        $carrier = $this->demoCarrier();
        $truck = $this->demoTruck($carrier);
        $driver = $this->demoDriver($carrier);

        $assignment = [
            'carrier_id' => $carrier->id,
            'driver_id' => $driver->id,
            'truck_id' => $truck->id,
        ];

        $openCarrier = $this->service('open-carrier-auto-transport');
        $enclosed = $this->service('enclosed-auto-transport');
        $expedited = $this->service('expedited-auto-transport');

        // 1. Delivered and already reviewed: the happy path, and the row that gives
        //    the service page a real rating average once the observer rebuilds it.
        $reviewed = Booking::factory()->delivered()->for($customer)->create(
            $assignment + ['service_id' => $openCarrier?->id]
        );
        $this->attachVehicle($reviewed, self::VEHICLES[0], delivered: true);
        $this->settle($reviewed, withBalance: true);
        $this->deliveredTimeline($reviewed);

        Review::factory()->for($reviewed)->create([
            'title' => 'Second time using them, same result',
            'body' => 'Quoted on a Sunday, collected on the Tuesday, delivered 1,300 miles away on the Friday. Photos at both ends and the balance was exactly what the booking screen said it would be.',
            'rating_overall' => 5,
        ])->approve($moderator);

        // 2. Delivered and NOT reviewed: canBeReviewed() is true here, which is what
        //    makes the mobile "leave a review" flow reachable on a fresh install.
        $reviewable = Booking::factory()->delivered()->for($customer)->create(
            $assignment + ['service_id' => $enclosed?->id]
        );
        $this->attachVehicle($reviewable, self::VEHICLES[2], delivered: true);
        $this->settle($reviewable, withBalance: true);
        $this->deliveredTimeline($reviewable);
        $reviewable->update(['review_requested_at' => now()->subDays(3)]);

        // 3. Mid-transit: gives the tracking screen a live timeline and a balance due.
        $inTransit = Booking::factory()->inTransit()->for($customer)->create(
            $assignment + ['service_id' => $expedited?->id]
        );
        $this->attachVehicle($inTransit, self::VEHICLES[1], delivered: false);
        $this->settle($inTransit, withBalance: false);
        $this->inTransitTimeline($inTransit);

        $this->seedOtherCustomerReviews($moderator, $assignment, [$openCarrier, $enclosed]);

        $this->command?->info(sprintf(
            'Demo data ready. Customer login: %s / password (driver: %s).',
            self::CUSTOMER_EMAIL,
            self::DRIVER_EMAIL,
        ));
    }

    /**
     * Three more approved reviews from other customers plus one left pending, so the
     * public listing has a spread of authors and the moderation queue has work in it.
     *
     * @param  array<string, int>  $assignment
     * @param  list<Service|null>  $services
     */
    private function seedOtherCustomerReviews(User $moderator, array $assignment, array $services): void
    {
        $customers = User::factory()->count(4)->customer()->create();

        foreach ($customers as $index => $customer) {
            $booking = Booking::factory()->delivered()->for($customer)->create(
                $assignment + ['service_id' => $services[$index % count($services)]?->id]
            );

            $this->attachVehicle($booking, self::VEHICLES[($index + 1) % count(self::VEHICLES)], delivered: true);
            $this->settle($booking, withBalance: true);
            $this->deliveredTimeline($booking);

            $review = Review::factory()->for($booking)->create();

            // The last one stays pending: an empty moderation queue on a fresh
            // install hides the whole feature from whoever is reviewing the build.
            if ($index < 3) {
                $review->approve($moderator);
            }
        }
    }

    private function demoCustomer(): User
    {
        $user = User::query()->firstOrNew(['email' => self::CUSTOMER_EMAIL]);

        if (! $user->exists) {
            $user->name = 'Dana Whitfield';
            $user->password = 'password';        // 'hashed' cast; local fixtures only
            $user->phone = '+12145550117';       // set mutator fills phone_normalized
        }

        // Verified on purpose: an unverified demo account cannot exercise the guest
        // quote claim in §4.10, and half the app's screens would be gated.
        $user->email_verified_at ??= now();
        $user->status = 'active';
        $user->save();

        $user->assignRole(Role::findOrCreate(UserRole::Customer->value, 'web'));

        return $user;
    }

    private function demoDriver(Carrier $carrier): User
    {
        $user = User::query()->firstOrNew(['email' => self::DRIVER_EMAIL]);

        if (! $user->exists) {
            $user->name = 'Marcus Hale';
            $user->password = 'password';
            $user->phone = '+12145550164';
        }

        $user->email_verified_at ??= now();
        $user->status = 'active';
        $user->save();

        $user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));

        DriverProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'carrier_id' => $carrier->id,
                'license_number' => 'TX-4471902',
                'license_state' => 'TX',
                'license_expires_at' => now()->addYears(3)->toDateString(),
                'cdl_class' => 'A',
                'is_available' => true,
                'last_lat' => 35.4676000,
                'last_lng' => -97.5164000,
                'last_ping_at' => now()->subHours(2),
            ],
        );

        return $user;
    }

    private function demoCarrier(): Carrier
    {
        return Carrier::query()->firstOrCreate(
            ['mc_number' => 'MC-884120'],
            [
                'company_name' => 'Lone Star Auto Logistics',
                'dot_number' => 'DOT-2617334',
                'contact_name' => 'Rosa Delgado',
                'email' => 'dispatch@lonestarautologistics.test',
                'phone' => '+12145550190',
                'insurance_provider' => 'Sentry Casualty',
                'insurance_policy_no' => 'CG-77-410882',
                'insurance_expires_at' => now()->addMonths(8)->toDateString(),
                'cargo_coverage_cents' => 25000000,   // $250,000
                'status' => Carrier::STATUS_APPROVED,
            ],
        );
    }

    private function demoTruck(Carrier $carrier): Truck
    {
        return Truck::query()->firstOrCreate(
            ['carrier_id' => $carrier->id, 'unit_number' => '118'],
            [
                'plate' => 'TX 8842RD',
                'trailer_type' => Truck::TRAILER_OPEN,
                'capacity_vehicles' => 9,
                'is_active' => true,
            ],
        );
    }

    /**
     * Review::approve() needs a real moderator id for the audit trail. The
     * super-admin from RolePermissionSeeder is the natural one; the fallback only
     * fires when this seeder is run in isolation.
     */
    private function moderator(): User
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', UserRole::SuperAdmin->value))
            ->first()
            ?? User::factory()->superAdmin()->create(['name' => 'Demo Moderator']);
    }

    private function service(string $slug): ?Service
    {
        return Service::query()->firstWhere('slug', $slug) ?? Service::query()->first();
    }

    /** @param array<string, mixed> $vehicle */
    private function attachVehicle(Booking $booking, array $vehicle, bool $delivered): void
    {
        $odometer = 41000 + random_int(0, 40000);

        $booking->vehicles()->create([
            'vehicle_type_id' => VehicleType::query()->where('slug', $vehicle['type'])->value('id'),
            'year' => $vehicle['year'],
            'make' => $vehicle['make'],
            'model' => $vehicle['model'],
            'color' => $vehicle['color'],
            'vin' => $vehicle['vin'],
            'is_operable' => true,
            'pickup_odometer' => $odometer,
            'pickup_condition_notes' => 'Minor stone chips on the front bumper, noted and photographed at loading.',
            // Four miles between the two readings: on and off the trailer. A larger
            // gap is the first thing a customer looks for in a damage dispute.
            'delivery_odometer' => $delivered ? $odometer + 4 : null,
            'delivery_condition_notes' => $delivered ? 'No new damage. Condition report signed by the recipient.' : null,
        ]);
    }

    /**
     * §4.11: payments are a ledger and bookings.amount_paid_cents is the signed sum
     * of captured rows -- recomputed from the ledger, never incremented in place.
     */
    private function settle(Booking $booking, bool $withBalance): void
    {
        $this->capture($booking, Payment::TYPE_DEPOSIT, (int) $booking->deposit_cents, now()->subDays(12));

        if ($withBalance) {
            $balance = (int) $booking->total_price_cents - (int) $booking->deposit_cents;
            $this->capture($booking, Payment::TYPE_BALANCE, $balance, now()->subDays(4));
        }

        $booking->update([
            'amount_paid_cents' => Payment::netCapturedCentsForBooking($booking->id),
        ]);
    }

    private function capture(Booking $booking, string $type, int $cents, \DateTimeInterface $paidAt): void
    {
        Payment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'type' => $type,
            'gateway' => 'manual',
            'gateway_reference' => 'demo_'.Str::lower(Str::random(14)),
            'idempotency_key' => $booking->booking_number.'-'.$type,   // UNIQUE column
            'amount_cents' => $cents,
            'currency' => $booking->currency,
            'status' => PaymentStatus::Captured,
            'card_brand' => 'visa',
            'card_last4' => '4242',      // the only card data allowed here (§4.11)
            'paid_at' => $paidAt,
        ]);
    }

    private function deliveredTimeline(Booking $booking): void
    {
        [$midLat, $midLng] = $this->midpoint($booking);

        $this->timeline($booking, [
            ['type' => 'status_change', 'to' => BookingStatus::Confirmed->value, 'days' => 12, 'description' => 'Deposit received. Booking confirmed.'],
            ['type' => 'status_change', 'to' => BookingStatus::Assigned->value, 'days' => 11, 'description' => 'Assigned to Lone Star Auto Logistics, unit 118.'],
            ['type' => 'status_change', 'to' => BookingStatus::PickedUp->value, 'days' => 9, 'description' => 'Loaded and bill of lading signed at pickup.', 'lat' => (float) $booking->pickup_lat, 'lng' => (float) $booking->pickup_lng],
            ['type' => 'status_change', 'to' => BookingStatus::InTransit->value, 'days' => 8, 'description' => 'On the road.'],
            ['type' => 'location_ping', 'days' => 6, 'description' => 'Overnight stop.', 'lat' => $midLat, 'lng' => $midLng],
            ['type' => 'status_change', 'to' => BookingStatus::Delivered->value, 'days' => 4, 'description' => 'Delivered. Condition report signed and balance settled.', 'lat' => (float) $booking->dropoff_lat, 'lng' => (float) $booking->dropoff_lng],
        ]);
    }

    private function inTransitTimeline(Booking $booking): void
    {
        [$midLat, $midLng] = $this->midpoint($booking);

        $this->timeline($booking, [
            ['type' => 'status_change', 'to' => BookingStatus::Confirmed->value, 'days' => 6, 'description' => 'Deposit received. Booking confirmed.'],
            ['type' => 'status_change', 'to' => BookingStatus::Assigned->value, 'days' => 5, 'description' => 'Assigned to Lone Star Auto Logistics, unit 118.'],
            ['type' => 'note', 'days' => 5, 'description' => 'Customer asked for a call one hour before pickup.', 'visible' => false],
            ['type' => 'status_change', 'to' => BookingStatus::PickedUp->value, 'days' => 3, 'description' => 'Loaded and bill of lading signed at pickup.', 'lat' => (float) $booking->pickup_lat, 'lng' => (float) $booking->pickup_lng],
            ['type' => 'status_change', 'to' => BookingStatus::InTransit->value, 'days' => 3, 'description' => 'On the road. Estimated delivery in two days.'],
            ['type' => 'location_ping', 'days' => 1, 'description' => 'Driver reported position.', 'lat' => $midLat, 'lng' => $midLng],
        ]);
    }

    /**
     * Events are written directly rather than through transitionTo() because the
     * booking is already in its final state and each event needs its own backdated
     * occurred_at -- transitionTo() stamps now(), which would collapse a two-week
     * shipment into one timestamp and make the tracking screen look broken.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function timeline(Booking $booking, array $steps): void
    {
        $previous = BookingStatus::PendingPayment->value;

        foreach ($steps as $step) {
            $isTransition = $step['type'] === 'status_change';

            $booking->recordEvent($step['type'], [
                'from_status' => $isTransition ? $previous : null,
                'to_status' => $step['to'] ?? null,
                'description' => $step['description'],
                'lat' => $step['lat'] ?? null,
                'lng' => $step['lng'] ?? null,
                'is_customer_visible' => $step['visible'] ?? true,
                'occurred_at' => now()->subDays($step['days']),
            ]);

            if ($isTransition) {
                $previous = $step['to'];
            }
        }
    }

    /** @return array{0: float|null, 1: float|null} */
    private function midpoint(Booking $booking): array
    {
        if ($booking->pickup_lat === null || $booking->dropoff_lat === null) {
            return [null, null];
        }

        // Straight-line midpoint, which is close enough for a demo ping and honest
        // about being an estimate -- real pings come from the driver's device.
        return [
            round(((float) $booking->pickup_lat + (float) $booking->dropoff_lat) / 2, 7),
            round(((float) $booking->pickup_lng + (float) $booking->dropoff_lng) / 2, 7),
        ];
    }
}
