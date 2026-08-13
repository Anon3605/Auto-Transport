<?php

namespace App\Policies;

use App\Models\QuoteRequest;
use App\Models\User;

/**
 * Discovered automatically by Laravel's naming convention -- see BookingPolicy.
 */
class QuoteRequestPolicy
{
    /** "My requests" is scoped to the caller by the query, not by this. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QuoteRequest $quoteRequest): bool
    {
        return $this->owns($user, $quoteRequest) || $this->viewAsStaff($user);
    }

    /** The quote form must work for a logged-out visitor (§4.10). */
    public function create(?User $user): bool
    {
        return true;
    }

    /**
     * quote_requests.user_id is NULLABLE -- an unclaimed guest lead has no owner.
     * The null check is load-bearing: a loose comparison would let any
     * authenticated user read every guest request, which is exactly the history
     * (addresses, phone numbers, VINs) §4.10 guards behind e-mail verification.
     */
    private function owns(User $user, QuoteRequest $quoteRequest): bool
    {
        return $quoteRequest->user_id !== null
            && (int) $quoteRequest->user_id === (int) $user->id;
    }

    private function viewAsStaff(User $user): bool
    {
        return $user->isStaff() && $user->can('view_quote_requests');
    }
}
