<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @var list<array{title: string, body: string}> */
    private const COPY = [
        ['title' => 'Exactly what was quoted', 'body' => 'Picked up on the second day of the window and delivered a day early. The price I was quoted was the price I paid, which is apparently rare in this industry.'],
        ['title' => 'Driver knew what he was doing', 'body' => 'Loaded a lowered car on a lift gate without a scratch and sent me photos from both ends. Kept me updated by text the whole way.'],
        ['title' => 'Straightforward from start to finish', 'body' => 'Booked on a Tuesday, collected on the Thursday, arrived across three states in four days. The tracking updates meant I never had to chase anyone.'],
        ['title' => 'Good communication, minor delay', 'body' => 'Delivery slipped by a day because of weather in the mountains, but dispatch called me before I had to call them. Car arrived clean and undamaged.'],
        ['title' => 'Would use again for the classic', 'body' => 'Enclosed transport for a restored car and it arrived exactly as it left. Soft straps over the tyres, nothing touching the paint.'],
        ['title' => 'Handled a difficult address', 'body' => 'The truck could not get down my street so we met at a retail park ten minutes away. The driver called an hour ahead and the handover took fifteen minutes.'],
    ];

    /**
     * A review is anchored to exactly one delivered booking (§4.7), and user_id and
     * service_id are copied off that booking rather than generated independently --
     * the application invariant is review.user_id === booking.user_id, and a factory
     * that violates it produces rows the API would never have written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $copy = fake()->randomElement(self::COPY);

        return [
            'booking_id' => Booking::factory()->delivered(),
            'user_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->user_id,
            'service_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->service_id,
            'carrier_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->carrier_id,
            'driver_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->driver_id,
            'rating_overall' => fake()->numberBetween(4, 5),
            'rating_communication' => fake()->numberBetween(3, 5),
            'rating_timeliness' => fake()->numberBetween(3, 5),
            'rating_condition' => fake()->numberBetween(4, 5),
            'rating_value' => fake()->numberBetween(3, 5),
            'title' => $copy['title'],
            'body' => $copy['body'],
            'is_featured' => false,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * The moderation queue's occupant. status is not fillable on the model, but
     * factories run inside Model::unguarded, so stating it here is legitimate --
     * production code still has to go through approve() / reject().
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Pending,
            'moderated_by' => null,
            'moderated_at' => null,
            'rejection_reason' => null,
        ]);
    }

    /**
     * moderated_by is left null: there is no moderator to point at unless the test
     * makes one, and a fabricated user id would break the FK.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Approved,
            'moderated_at' => now()->subDay(),
            'rejection_reason' => null,
        ]);
    }

    public function rejected(string $reason = 'Contains contact details.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Rejected,
            'moderated_at' => now()->subDay(),
            'rejection_reason' => $reason,
        ]);
    }

    /** Homepage testimonial slot -- featured only means anything once approved. */
    public function featured(): static
    {
        return $this->approved()->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    public function withReply(string $reply = 'Thank you for the review -- passed on to the driver and his dispatcher.'): static
    {
        return $this->state(fn (array $attributes) => [
            'admin_reply' => $reply,
            'admin_replied_at' => now(),
        ]);
    }
}
