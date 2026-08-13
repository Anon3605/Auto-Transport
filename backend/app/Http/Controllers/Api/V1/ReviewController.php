<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reviews are the one write path a customer can use to publish text on a
 * marketing surface, so every method here fails closed: nothing is created that
 * is not anchored to the caller's own delivered booking, and nothing is served
 * publicly that a moderator has not approved (design doc §4.7).
 */
class ReviewController extends Controller
{
    use AuthorizesRequests;

    private const DEFAULT_PER_PAGE = 15;

    /** A client asking for 10,000 reviews is a denial of service, not a feature. */
    private const MAX_PER_PAGE = 50;

    /**
     * GET /services/{service}/reviews -- public.
     *
     * Implicit binding resolves the slug, because Service::getRouteKeyName()
     * returns 'slug' (endpoints.ts addresses services by slug, not by id).
     */
    public function index(Request $request, Service $service): AnonymousResourceCollection
    {
        $perPage = max(1, min($request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        $reviews = $service->approvedReviews()
            // author_name reads through to users.name; without this the listing is
            // one extra query per row on the highest-traffic SEO surface.
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')          // created_at ties are common in a seeded/imported batch
            ->paginate($perPage)
            ->appends($request->query());

        return ReviewResource::collection($reviews);
    }

    /**
     * POST /reviews -- auth.
     *
     * StoreReviewRequest has already proved the booking is the caller's, is
     * delivered, and has no review yet.
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $booking = $request->reviewableBooking();

        // Unreachable: booking_id's exists rule is scoped to this user's bookings.
        // Kept so a future edit to that rule cannot turn into a null dereference.
        abort_if($booking === null, Response::HTTP_NOT_FOUND);

        // Second lock. The FormRequest exists to produce a friendly 422; the policy
        // exists so no other write path can ever skip the rule.
        $this->authorize('create', [Review::class, $booking]);

        $data = $request->validated();

        $review = new Review([
            'booking_id' => $booking->id,
            'user_id' => $user->id,

            // §4.7: the subjects are SNAPSHOTTED at write time. Reassigning this
            // booking to another carrier next month must not silently move the
            // customer's rating with it, and the aggregate queries stay join-free.
            'service_id' => $booking->service_id,
            'carrier_id' => $booking->carrier_id,
            'driver_id' => $booking->driver_id,

            'rating_overall' => $data['rating_overall'],
            'rating_communication' => $data['rating_communication'] ?? null,
            'rating_timeliness' => $data['rating_timeliness'] ?? null,
            'rating_condition' => $data['rating_condition'] ?? null,
            'rating_value' => $data['rating_value'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,

            // Spam forensics for the moderation queue; never rendered publicly.
            'ip_address' => $request->ip(),
        ]);

        /**
         * Neither column is fillable and neither may ever originate in the request
         * body: `status` is the fail-closed moderation gate, and `is_verified` is
         * the badge asserting we hold the shipment record behind this review. They
         * are restated here rather than left to the model's defaults so the
         * guarantee is visible at the only place a customer can create a review.
         */
        $review->status = ReviewStatus::Pending;
        $review->is_verified = true;

        try {
            $review->save();
        } catch (UniqueConstraintViolationException) {
            /**
             * Two submissions in flight at once: both passed the after() check and
             * reviews.booking_id UNIQUE arbitrated between them. The loser gets the
             * same 422 the sequential case gets -- a double-tapped submit button is
             * not a server error.
             */
            throw ValidationException::withMessages([
                'booking_ulid' => 'You have already reviewed this shipment.',
            ]);
        }

        // `booking` is loaded so the response carries booking_ulid: this is the
        // owner's own review, where echoing back which shipment it landed on is
        // the point. The public listing does not load it and so does not leak it.
        return ReviewResource::make($review->loadMissing(['user', 'booking']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /reviews/{review}/helpful -- auth. Toggles the caller's vote and
     * returns the new tally, so the client never has to guess it.
     */
    public function helpful(Request $request, Review $review): JsonResponse
    {
        $this->authorize('voteHelpful', $review);

        /** @var User $user */
        $user = $request->user();

        $count = DB::transaction(function () use ($review, $user): int {
            $existing = $review->votes()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->delete();     // tapping again withdraws the vote
            } else {
                try {
                    $review->votes()->create([
                        'user_id' => $user->id,
                        'is_helpful' => true,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Raced past the row lock. The unique index already put the
                    // table in the state this branch was trying to reach.
                }
            }

            /**
             * Recounted, never incremented. reviews.helpful_count is a cache of
             * review_votes, and an increment that is lost once is wrong forever --
             * the same argument §4.7 makes for rating_avg.
             */
            $count = $review->votes()->where('is_helpful', true)->count();

            // helpful_count is deliberately not fillable; forceFill is the way in.
            // Quietly, because a vote tally is not a change to the review's content
            // and must not wake the rating aggregator or the activity log.
            $review->forceFill(['helpful_count' => $count])->saveQuietly();

            return $count;
        });

        return response()->json(['helpful_count' => $count]);
    }
}
