<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * Real lanes, real coordinates: a booking with a zeroed lat/lng puts the
     * tracking map in the Gulf of Guinea, which reads as a bug in the app.
     *
     * @var list<array<string, mixed>>
     */
    private const CITIES = [
        ['city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75202', 'lat' => 32.7791000, 'lng' => -96.8008000, 'line1' => '1200 Main Street'],
        ['city' => 'Los Angeles', 'state' => 'CA', 'postal_code' => '90021', 'lat' => 34.0225000, 'lng' => -118.2277000, 'line1' => '2450 E Washington Blvd'],
        ['city' => 'Chicago', 'state' => 'IL', 'postal_code' => '60632', 'lat' => 41.8069000, 'lng' => -87.7328000, 'line1' => '4700 S Kildare Avenue'],
        ['city' => 'Phoenix', 'state' => 'AZ', 'postal_code' => '85004', 'lat' => 33.4520000, 'lng' => -112.0740000, 'line1' => '455 N Central Avenue'],
        ['city' => 'Atlanta', 'state' => 'GA', 'postal_code' => '30303', 'lat' => 33.7527000, 'lng' => -84.3900000, 'line1' => '180 Peachtree Street NW'],
        ['city' => 'Denver', 'state' => 'CO', 'postal_code' => '80202', 'lat' => 39.7481000, 'lng' => -104.9968000, 'line1' => '1550 Wewatta Street'],
        ['city' => 'Seattle', 'state' => 'WA', 'postal_code' => '98104', 'lat' => 47.5990000, 'lng' => -122.3331000, 'line1' => '620 4th Avenue S'],
        ['city' => 'Miami', 'state' => 'FL', 'postal_code' => '33132', 'lat' => 25.7796000, 'lng' => -80.1908000, 'line1' => '1050 NE 2nd Avenue'],
    ];

    /**
     * booking_number and ulid are absent: Booking::booted() draws the reference
     * from App\Support\Reference and HasUlid fills the ulid, both on creating.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array{city: string, state: string, postal_code: string, lat: float, lng: float, line1: string} $pickup */
        /** @var array{city: string, state: string, postal_code: string, lat: float, lng: float, line1: string} $dropoff */
        [$pickup, $dropoff] = fake()->randomElements(self::CITIES, 2);

        $totalPriceCents = fake()->numberBetween(600, 2400) * 100;
        $pickupDate = fake()->dateTimeBetween('+2 days', '+3 weeks');

        return [
            'quote_id' => null,
            'quote_request_id' => null,
            'user_id' => User::factory(),
            'service_id' => Service::factory(),
            'status' => BookingStatus::PendingPayment,

            'pickup_contact_name' => fake()->name(),
            'pickup_contact_phone' => fake()->numerify('+1##########'),
            'pickup_line1' => $pickup['line1'],
            'pickup_city' => $pickup['city'],
            'pickup_state' => $pickup['state'],
            'pickup_postal_code' => $pickup['postal_code'],
            'pickup_country_code' => 'US',
            'pickup_lat' => $pickup['lat'],
            'pickup_lng' => $pickup['lng'],

            'dropoff_contact_name' => fake()->name(),
            'dropoff_contact_phone' => fake()->numerify('+1##########'),
            'dropoff_line1' => $dropoff['line1'],
            'dropoff_city' => $dropoff['city'],
            'dropoff_state' => $dropoff['state'],
            'dropoff_postal_code' => $dropoff['postal_code'],
            'dropoff_country_code' => 'US',
            'dropoff_lat' => $dropoff['lat'],
            'dropoff_lng' => $dropoff['lng'],

            'scheduled_pickup_date' => $pickupDate,
            'scheduled_delivery_date' => (clone $pickupDate)->modify('+5 days'),
            'distance_miles' => fake()->numberBetween(120, 2800),

            // 20% deposit, matching the pricing.deposit_percent default (§6.6).
            'total_price_cents' => $totalPriceCents,
            'deposit_cents' => (int) round($totalPriceCents * 0.20),
            'amount_paid_cents' => 0,
            'currency' => 'USD',
            'special_instructions' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed,
            'amount_paid_cents' => $attributes['deposit_cents'],
        ]);
    }

    public function inTransit(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::InTransit,
            'dispatched_at' => now()->subDays(4),
            'actual_pickup_at' => now()->subDays(3),
            'scheduled_pickup_date' => now()->subDays(3),
            'scheduled_delivery_date' => now()->addDays(2),
            'amount_paid_cents' => $attributes['deposit_cents'],
        ]);
    }

    /**
     * The state the review flow depends on: Booking::canBeReviewed() is true only
     * for a delivered booking with no review attached, and BookingStatus::Delivered
     * is terminal, so nothing downstream can move it back out.
     */
    public function delivered(): static
    {
        return $this->state(function (array $attributes): array {
            $pickedUpAt = now()->subDays(9);
            $deliveredAt = now()->subDays(4);

            return [
                'status' => BookingStatus::Delivered,
                'dispatched_at' => now()->subDays(11),
                'scheduled_pickup_date' => $pickedUpAt,
                'scheduled_delivery_date' => $deliveredAt,
                'actual_pickup_at' => $pickedUpAt,
                'actual_delivery_at' => $deliveredAt,
                // Balance is collected before the keys change hands, so a delivered
                // booking is a settled one and balance_due comes out at zero.
                'amount_paid_cents' => $attributes['total_price_cents'],
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now()->subDay(),
            'cancellation_reason' => 'Customer sold the vehicle locally.',
        ]);
    }
}
