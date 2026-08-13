<?php

namespace App\Policies;

use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Review;
use App\Models\User;

/**
 * Discovered automatically: App\Models\Review -> App\Policies\ReviewPolicy is
 * Laravel's default convention, so no registration is needed (and the model
 * file, where a #[UsePolicy] attribute would go, is owned elsewhere).
 *
 * ULIDs make a review hard to guess. They do not make it authorized -- every
 * ability below is still checked at the endpoint.
 */
class ReviewPolicy
{
    /** The public listing is filtered to approved rows by the query, not by this. */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * An approved review is public. A pending or rejected one is visible only to
     * its author (so they can see it is in the queue) and to moderators.
     */
    public function view(?User $user, Review $review): bool
    {
        if ($review->status === ReviewStatus::Approved) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->id === $review->user_id || $this->moderate($user);
    }

    /**
     * Called with the booking being reviewed. Without one it answers the weaker
     * question "may this user write reviews at all", which is true for any
     * authenticated account.
     *
     * These are the same three conditions StoreReviewRequest validates; the
     * duplication is deliberate. Validation exists to produce a friendly 422,
     * authorization to make sure no other write path (admin action, queued job,
     * future endpoint) can skip the rule.
     */
    public function create(User $user, ?Booking $booking = null): bool
    {
        if ($booking === null) {
            return true;
        }

        return $booking->user_id === $user->id
            && $booking->status->allowsReview()
            && $booking->review()->withTrashed()->doesntExist();
    }

    /**
     * You cannot vote your own review helpful. Restricted to approved reviews
     * because nothing else is publicly visible, so a vote on anything else is
     * either a mistake or someone poking at ulids.
     */
    public function voteHelpful(User $user, Review $review): bool
    {
        return $review->status === ReviewStatus::Approved
            && $user->id !== $review->user_id;
    }

    /**
     * Moderation is a permission, not a hardcoded role list, so the grant can be
     * edited in the admin panel (see UserRole's docblock). The isStaff() gate in
     * front of it means a permission attached to a customer-facing role by
     * accident still cannot open the queue.
     */
    public function moderate(User $user): bool
    {
        return $user->isStaff() && $user->can('moderate_reviews');
    }
}
