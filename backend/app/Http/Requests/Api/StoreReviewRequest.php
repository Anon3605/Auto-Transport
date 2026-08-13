<?php

namespace App\Http\Requests\Api;

use App\Models\Booking;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors reviewSchema in mobile/src/types/schemas.ts, then adds the three
 * checks a foreign key cannot express (design doc §4.7):
 *
 *   1. the booking belongs to the caller      -> the scoped exists rule below
 *   2. the booking has been delivered         -> after() hook
 *   3. the booking has no review yet          -> after() hook
 *
 * 2 and 3 live in after() rather than in the controller so they come back in the
 * normal 422 error bag the client's form already knows how to render.
 */
class StoreReviewRequest extends FormRequest
{
    private ?Booking $booking = null;

    private bool $bookingResolved = false;

    /**
     * The route carries auth:sanctum; this only stops a 422 that reads like a
     * validation failure if that middleware is ever dropped from the route.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /**
             * The shipment is identified by its ULID, which is the only booking
             * identifier the API ever emits (§4.5). An earlier draft took the
             * integer id, but BookingResource does not expose one -- there was no
             * way for the client to obtain it, so the endpoint was unreachable
             * from the app it was written for.
             *
             * Routing on the ULID also keeps the sequential id server-side, so a
             * customer cannot infer total booking volume from their own payload.
             * The existence rule is still scoped to the caller's own bookings:
             * an unguessable key is not authorization, and probing a stranger's
             * ULID must return the same "not found" as an invented one.
             */
            'booking_ulid' => [
                'required',
                'string',
                'size:26',
                Rule::exists('bookings', 'ulid')
                    ->where('user_id', $this->user()?->id)
                    ->whereNull('deleted_at'),
            ],

            'rating_overall' => ['required', 'integer', 'between:1,5'],

            // schemas.ts marks the sub-ratings nullable-but-present. Absent is
            // treated as null here: it carries the same meaning ("skipped") and
            // rejecting a missing key would only break non-app clients.
            'rating_communication' => ['nullable', 'integer', 'between:1,5'],
            'rating_timeliness' => ['nullable', 'integer', 'between:1,5'],
            'rating_condition' => ['nullable', 'integer', 'between:1,5'],
            'rating_value' => ['nullable', 'integer', 'between:1,5'],

            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'booking_ulid.exists' => 'We could not find that shipment on your account.',
            'booking_ulid.required' => 'We could not tell which shipment this review is for.',
            'rating_overall.required' => 'Pick a rating.',
            'rating_overall.between' => 'Ratings run from 1 to 5 stars.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $booking = $this->reviewableBooking();

                // booking_ulid already failed the exists rule; a second error on
                // the same field would just be noise.
                if ($booking === null) {
                    return;
                }

                if (! $booking->status->allowsReview()) {
                    $validator->errors()->add(
                        'booking_ulid',
                        'You can review a shipment once it has been delivered.'
                    );
                }

                /**
                 * withTrashed() is the point: reviews.booking_id is UNIQUE and a
                 * soft-deleted row still occupies the index, so ignoring trashed
                 * reviews here turns a re-submission into a QueryException and a
                 * 500. One review per shipment, deleted or not.
                 */
                if ($booking->review()->withTrashed()->exists()) {
                    $validator->errors()->add(
                        'booking_ulid',
                        'You have already reviewed this shipment.'
                    );
                }
            },
        ];
    }

    /**
     * The caller's booking, or null when booking_ulid is absent, malformed or
     * not theirs. Memoised so validation and the controller's snapshot share one
     * query -- and so the controller can never widen the ownership scope.
     */
    public function reviewableBooking(): ?Booking
    {
        if ($this->bookingResolved) {
            return $this->booking;
        }

        $this->bookingResolved = true;

        $ulid = $this->input('booking_ulid');
        $user = $this->user();

        if ($user === null || ! is_string($ulid) || $ulid === '') {
            return $this->booking = null;
        }

        return $this->booking = Booking::query()
            ->where('ulid', $ulid)
            ->where('user_id', $user->id)
            ->first();
    }
}
