<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Carrier;
use App\Models\DriverProfile;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The review API is the one place a customer can publish text on a marketing
 * surface, so these tests are mostly about what must NOT happen: no review
 * without a delivered booking of your own, no second review on a shipment, no
 * unmoderated review in a public listing, no vote counted twice.
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The PUBLIC review shape, as rendered on a service page.
     *
     * booking_ulid is deliberately absent. A visitor reading testimonials has no
     * business holding a shipment reference, and the omission is enforced by
     * ReviewResource only emitting it when the booking relation is loaded -- so
     * this list failing is the alarm that a leak has been introduced.
     */
    private const CONTRACT_KEYS = [
        'ulid',
        'rating_overall',
        'rating_communication',
        'rating_timeliness',
        'rating_condition',
        'rating_value',
        'title',
        'body',
        'status',
        'admin_reply',
        'helpful_count',
        'author_name',
        'created_at',
    ];

    /**
     * The OWNER's shape, returned when they create their own review: the public
     * fields plus the shipment it was filed against.
     */
    private const OWNER_CONTRACT_KEYS = [...self::CONTRACT_KEYS, 'booking_ulid'];

    public function test_the_review_endpoints_are_registered_under_api_v1(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains((string) $route->getAction('controller'), 'ReviewController'))
            // {service:slug} and {service} both bind; normalise so this asserts the
            // path, not the binding style.
            ->map(fn ($route): string => $route->methods()[0].' '.preg_replace('/\{(\w+)(:\w+)?\??\}/', '{$1}', $route->uri()))
            ->all();

        foreach ([
            'POST api/v1/reviews',
            'GET api/v1/services/{service}/reviews',
            'POST api/v1/reviews/{review}/helpful',
        ] as $expected) {
            $this->assertContains($expected, $registered, "routes/api.php does not register: {$expected}");
        }
    }

    public function test_the_rating_observer_is_wired_into_the_application(): void
    {
        $this->assertTrue(
            Event::hasListeners('eloquent.updated: '.Review::class),
            'ReviewObserver is not registered: add Review::observe(ReviewObserver::class) to AppServiceProvider::boot().'
        );
    }

    public function test_the_owner_of_a_delivered_booking_can_review_it(): void
    {
        $user = User::factory()->create(['name' => 'Sarah Mendoza']);
        $service = $this->service();
        $carrier = Carrier::create(['company_name' => 'Gulf Coast Auto Haul']);
        $driver = User::factory()->create();

        $booking = $this->deliveredBooking($user, [
            'service_id' => $service->id,
            'carrier_id' => $carrier->id,
            'driver_id' => $driver->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/reviews', $this->payload($booking));

        $response->assertCreated()
            ->assertJsonPath('data.booking_ulid', $booking->ulid)
            ->assertJsonPath('data.rating_overall', 5)
            ->assertJsonPath('data.rating_timeliness', 4)
            ->assertJsonPath('data.status', ReviewStatus::Pending->value)
            ->assertJsonPath('data.helpful_count', 0)
            ->assertJsonPath('data.admin_reply', null)
            // The full name never leaves the database (Review::authorName()).
            ->assertJsonPath('data.author_name', 'Sarah M.');

        $this->assertEqualsCanonicalizing(self::OWNER_CONTRACT_KEYS, array_keys($response->json('data')));

        $review = Review::query()->sole();

        // Â§4.7 snapshot: copied off the booking at write time, not resolved later.
        $this->assertSame($service->id, $review->service_id);
        $this->assertSame($carrier->id, $review->carrier_id);
        $this->assertSame($driver->id, $review->driver_id);

        $this->assertSame($user->id, $review->user_id);
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertTrue($review->is_verified);
        $this->assertSame('127.0.0.1', $review->ip_address);

        // Nothing public yet, so nothing aggregated yet.
        $this->assertSame(0, $service->fresh()->rating_count);
    }

    public function test_status_and_is_verified_are_server_side_only(): void
    {
        $user = User::factory()->create();
        $booking = $this->deliveredBooking($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/reviews', $this->payload($booking, [
            'status' => ReviewStatus::Approved->value,
            'is_verified' => false,
            'helpful_count' => 500,
            'user_id' => User::factory()->create()->id,
        ]))->assertCreated();

        $review = Review::query()->sole();

        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertTrue($review->is_verified);
        $this->assertSame(0, $review->helpful_count);
        $this->assertSame($user->id, $review->user_id);
    }

    public function test_a_booking_that_is_not_delivered_cannot_be_reviewed(): void
    {
        $user = User::factory()->create();
        $booking = $this->deliveredBooking($user, ['status' => BookingStatus::InTransit]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/reviews', $this->payload($booking))
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_ulid');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_second_review_on_the_same_booking_is_rejected_with_422_not_500(): void
    {
        $user = User::factory()->create();
        $booking = $this->deliveredBooking($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/reviews', $this->payload($booking))->assertCreated();

        // reviews.booking_id is UNIQUE. Reaching the insert would be a
        // QueryException and a 500; the after() check turns it into a form error.
        $this->postJson('/api/v1/reviews', $this->payload($booking, ['rating_overall' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_ulid');

        $this->assertSame(1, Review::query()->count());
        $this->assertSame(5, Review::query()->sole()->rating_overall);
    }

    public function test_a_soft_deleted_review_still_blocks_a_resubmission(): void
    {
        $user = User::factory()->create();
        $booking = $this->deliveredBooking($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/reviews', $this->payload($booking))->assertCreated();

        // A trashed row keeps its slot in the unique index, so this is the other
        // way the endpoint could 500.
        Review::query()->sole()->delete();

        $this->postJson('/api/v1/reviews', $this->payload($booking))
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_ulid');
    }

    public function test_a_user_cannot_review_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $booking = $this->deliveredBooking($owner);

        Sanctum::actingAs($intruder);

        $response = $this->postJson('/api/v1/reviews', $this->payload($booking))
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_ulid');

        // The rejection must not confirm that the booking id exists -- the id is
        // sequential, so a different message here would be an enumeration oracle.
        $this->assertSame(
            'We could not find that shipment on your account.',
            $response->json('errors.booking_ulid.0')
        );

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_guest_cannot_post_a_review(): void
    {
        $booking = $this->deliveredBooking(User::factory()->create());

        $this->postJson('/api/v1/reviews', $this->payload($booking))->assertUnauthorized();

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $user = User::factory()->create();
        $booking = $this->deliveredBooking($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/reviews', $this->payload($booking, ['rating_overall' => 6]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating_overall');

        $this->postJson('/api/v1/reviews', $this->payload($booking, ['rating_condition' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating_condition');
    }

    public function test_a_pending_review_is_absent_from_the_public_service_listing(): void
    {
        $user = User::factory()->create();
        $service = $this->service();
        $booking = $this->deliveredBooking($user, ['service_id' => $service->id]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/reviews', $this->payload($booking))->assertCreated();

        // Listing is public: no token on this request.
        $this->getJson("/api/v1/services/{$service->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_approving_a_review_publishes_it_and_moves_the_service_aggregate(): void
    {
        $service = $this->service();
        $moderator = User::factory()->create();

        $five = $this->pendingReview(User::factory()->create(['name' => 'Ana Ruiz']), $service, 5);
        $four = $this->pendingReview(User::factory()->create(), $service, 4);

        $five->approve($moderator);

        $service->refresh();
        $this->assertSame(1, $service->rating_count);
        $this->assertSame(5.0, (float) $service->rating_avg);

        $four->approve($moderator);

        $service->refresh();
        $this->assertSame(2, $service->rating_count);
        $this->assertSame(4.5, (float) $service->rating_avg);

        $response = $this->getJson("/api/v1/services/{$service->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(self::CONTRACT_KEYS, array_keys($response->json('data.0')));

        // Deleting an approved review has to give the average back.
        $five->delete();

        $service->refresh();
        $this->assertSame(1, $service->rating_count);
        $this->assertSame(4.0, (float) $service->rating_avg);

        // And rejecting the last one leaves a clean 0/0, not a divide by zero.
        $four->reject($moderator, 'Off topic.');

        $service->refresh();
        $this->assertSame(0, $service->rating_count);
        $this->assertSame(0.0, (float) $service->rating_avg);
    }

    public function test_approval_moves_the_carrier_and_driver_aggregates_too(): void
    {
        $service = $this->service();
        $carrier = Carrier::create(['company_name' => 'Gulf Coast Auto Haul']);
        $driver = User::factory()->create();
        $profile = DriverProfile::create(['user_id' => $driver->id, 'carrier_id' => $carrier->id]);

        $review = $this->pendingReview(User::factory()->create(), $service, 4, [
            'carrier_id' => $carrier->id,
            'driver_id' => $driver->id,
        ]);

        $review->approve(User::factory()->create());

        $this->assertSame(1, $carrier->fresh()->rating_count);
        $this->assertSame(4.0, (float) $carrier->fresh()->rating_avg);

        // reviews.driver_id points at users.id; the aggregate lives on the profile.
        $this->assertSame(1, $profile->fresh()->rating_count);
        $this->assertSame(4.0, (float) $profile->fresh()->rating_avg);
    }

    public function test_repointing_an_approved_review_corrects_the_service_it_left(): void
    {
        $from = $this->service('open-auto-transport');
        $to = $this->service('enclosed-auto-transport');

        $review = $this->pendingReview(User::factory()->create(), $from, 5);
        $review->approve(User::factory()->create());

        $this->assertSame(1, $from->fresh()->rating_count);

        // A moderator fixing a mis-snapshotted review must not leave the rating
        // behind on the service the review no longer names.
        $review->update(['service_id' => $to->id]);

        $this->assertSame(0, $from->fresh()->rating_count);
        $this->assertSame(0.0, (float) $from->fresh()->rating_avg);
        $this->assertSame(1, $to->fresh()->rating_count);
        $this->assertSame(5.0, (float) $to->fresh()->rating_avg);
    }

    public function test_a_helpful_vote_toggles_and_stays_idempotent_per_user(): void
    {
        $review = $this->pendingReview(User::factory()->create(), $this->service(), 5);
        $review->approve(User::factory()->create());

        $voter = User::factory()->create();
        Sanctum::actingAs($voter);

        $this->postJson("/api/v1/reviews/{$review->ulid}/helpful")
            ->assertOk()
            ->assertExactJson(['helpful_count' => 1]);

        $this->assertSame(1, $review->fresh()->helpful_count);
        $this->assertSame(1, DB::table('review_votes')->count());

        // Tapping again withdraws the vote rather than stacking a second row.
        $this->postJson("/api/v1/reviews/{$review->ulid}/helpful")
            ->assertOk()
            ->assertExactJson(['helpful_count' => 0]);

        $this->assertSame(0, $review->fresh()->helpful_count);
        $this->assertSame(0, DB::table('review_votes')->count());

        $this->postJson("/api/v1/reviews/{$review->ulid}/helpful")
            ->assertOk()
            ->assertExactJson(['helpful_count' => 1]);

        // One row per user per review, whatever the tap count.
        $this->assertSame(
            1,
            DB::table('review_votes')->where('user_id', $voter->id)->where('review_id', $review->id)->count()
        );

        // A second voter adds to the same tally.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/reviews/{$review->ulid}/helpful")
            ->assertOk()
            ->assertExactJson(['helpful_count' => 2]);
    }

    public function test_an_author_cannot_vote_their_own_review_helpful(): void
    {
        $author = User::factory()->create();
        $review = $this->pendingReview($author, $this->service(), 5);
        $review->approve(User::factory()->create());

        Sanctum::actingAs($author);

        $this->postJson("/api/v1/reviews/{$review->ulid}/helpful")->assertForbidden();

        $this->assertSame(0, DB::table('review_votes')->count());
    }

    public function test_an_unapproved_review_cannot_be_voted_on(): void
    {
        $review = $this->pendingReview(User::factory()->create(), $this->service(), 5);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/reviews/{$review->ulid}/helpful")->assertForbidden();
    }

    public function test_the_rebuild_command_repairs_drifted_aggregates(): void
    {
        $service = $this->service();
        $carrier = Carrier::create(['company_name' => 'Gulf Coast Auto Haul']);
        $driver = User::factory()->create();
        $profile = DriverProfile::create(['user_id' => $driver->id]);

        $review = $this->pendingReview(User::factory()->create(), $service, 3, [
            'carrier_id' => $carrier->id,
            'driver_id' => $driver->id,
        ]);
        $review->approve(User::factory()->create());

        // Simulate the drift Â§4.7 warns about: a listener that never ran.
        DB::table('services')->where('id', $service->id)->update(['rating_avg' => 5, 'rating_count' => 99]);
        DB::table('carriers')->where('id', $carrier->id)->update(['rating_avg' => 1, 'rating_count' => 0]);
        DB::table('driver_profiles')->where('id', $profile->id)->update(['rating_avg' => 0, 'rating_count' => 7]);

        $this->artisan('reviews:rebuild-aggregates')->assertSuccessful();

        $this->assertSame(1, $service->fresh()->rating_count);
        $this->assertSame(3.0, (float) $service->fresh()->rating_avg);
        $this->assertSame(1, $carrier->fresh()->rating_count);
        $this->assertSame(3.0, (float) $carrier->fresh()->rating_avg);
        $this->assertSame(1, $profile->fresh()->rating_count);
        $this->assertSame(3.0, (float) $profile->fresh()->rating_avg);
    }

    public function test_the_rebuild_command_zeroes_a_service_with_no_approved_reviews(): void
    {
        $service = $this->service();

        // An approved review that was later trashed must not keep feeding the average.
        $review = $this->pendingReview(User::factory()->create(), $service, 5);
        $review->approve(User::factory()->create());
        DB::table('reviews')->where('id', $review->id)->update(['deleted_at' => now()]);

        $this->artisan('reviews:rebuild-aggregates')->assertSuccessful();

        $service->refresh();
        $this->assertSame(0, $service->rating_count);
        $this->assertSame(0.0, (float) $service->rating_avg);
    }

    private function service(string $slug = 'enclosed-auto-transport'): Service
    {
        return Service::create([
            'name' => 'Enclosed Auto Transport',
            'slug' => $slug,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function deliveredBooking(User $user, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'user_id' => $user->id,
            'status' => BookingStatus::Delivered,
            'pickup_line1' => '100 Ocean Drive',
            'pickup_city' => 'Miami',
            'dropoff_line1' => '900 N Michigan Ave',
            'dropoff_city' => 'Chicago',
            'total_price_cents' => 129900,
        ], $attributes));
    }

    /**
     * A review straight from the model layer -- these fixtures are about what
     * happens AFTER a review exists, and going through the endpoint every time
     * would only re-test store().
     *
     * @param  array<string, mixed>  $attributes
     */
    private function pendingReview(User $author, Service $service, int $rating, array $attributes = []): Review
    {
        $booking = $this->deliveredBooking($author, [
            'service_id' => $service->id,
            'carrier_id' => $attributes['carrier_id'] ?? null,
            'driver_id' => $attributes['driver_id'] ?? null,
        ]);

        return Review::create(array_merge([
            // The model writes the real column; only the HTTP contract speaks ULID.
            'booking_id' => $booking->id,
            'user_id' => $author->id,
            'service_id' => $service->id,
            'rating_overall' => $rating,
            'body' => 'Fixture review.',
        ], $attributes));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(Booking $booking, array $overrides = []): array
    {
        return array_merge([
            'booking_ulid' => $booking->ulid,
            'rating_overall' => 5,
            'rating_communication' => 5,
            'rating_timeliness' => 4,
            'rating_condition' => 5,
            'rating_value' => 4,
            'title' => 'Flawless door to door',
            'body' => 'Car arrived exactly as it left and the driver called ahead both ways.',
        ], $overrides);
    }
}
