<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Discovered automatically: App\Models\Booking -> App\Policies\BookingPolicy is
 * Laravel's default convention, so no registration is needed (and the model
 * file, where a #[UsePolicy] attribute would go, is owned elsewhere).
 *
 * A booking ULID is unguessable, which is why it is the public identifier -- but
 * §4.5 is explicit that ULIDs are defence in depth, not authorization. Every
 * ability below is checked at the endpoint even though the id is a needle in a
 * 2^80 haystack.
 */
class BookingPolicy
{
    /** The listing is scoped to the caller by the query, not by this. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may book. The booking is written against their own
     * account by BookService, which reads user_id from the authenticated caller
     * and never from the payload -- so there is no "book on behalf of" hole to
     * guard here.
     *
     * Suspended accounts are turned away: a suspended user is one operations has
     * deliberately stopped doing business with, and letting them open a new
     * shipment would quietly undo that decision.
     */
    public function create(User $user): bool
    {
        return $user->status === 'active';
    }

    /**
     * The owner, or staff with the read grant. Support and dispatch answer "where
     * is my car" calls, so they need the same record the customer sees.
     */
    public function view(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking) || $this->viewAsStaff($user);
    }

    /**
     * Owner only, deliberately. Staff cancelling a shipment is an operations
     * action with refund consequences and belongs in the admin panel behind its
     * own permission, not on a customer endpoint that happens to accept any ULID.
     *
     * Whether the booking is *still* cancellable is not asked here: BookingStatus
     * owns that rule, and letting transitionTo() refuse turns "already delivered"
     * into a 422 the customer can read instead of a bare 403.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking);
    }

    private function owns(User $user, Booking $booking): bool
    {
        // bookings.user_id is NOT NULL, so no null-equals-null hole here.
        return (int) $booking->user_id === (int) $user->id;
    }

    /**
     * A permission, not a hardcoded role list, so the grant is editable from the
     * admin panel. isStaff() in front of it means a permission attached to a
     * customer role by accident still opens nothing.
     */
    private function viewAsStaff(User $user): bool
    {
        return $user->isStaff() && $user->can('view_bookings');
    }
}
