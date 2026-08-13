<?php

namespace App\Listeners;

use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Attaches a visitor's guest quote history to their new account.
 *
 * This listens for Verified and deliberately NOT for Registered. Design doc
 * §4.10: quote_requests.user_id is nullable so an anonymous visitor can get a
 * price without signing up, and the only thing linking those rows to a person is
 * contact_email. Claiming at registration would let anyone sign up with a
 * stranger's address and inherit their quote history -- pickup addresses, phone
 * numbers, vehicle VINs. Proving control of the mailbox is what makes the email
 * match trustworthy, so the verification event is the earliest safe trigger.
 */
class ClaimGuestQuoteRequests
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        // The event is typed against MustVerifyEmail, not our concrete User.
        if (! $user instanceof User) {
            return;
        }

        // Verified is only dispatched after markEmailAsVerified(), but the whole
        // security property rests on this flag, so it is asserted rather than
        // assumed -- a future caller re-dispatching the event by hand cannot
        // bypass the gate.
        if (! $user->hasVerifiedEmail()) {
            return;
        }

        QuoteRequest::claimGuestRequestsFor($user);
    }
}
