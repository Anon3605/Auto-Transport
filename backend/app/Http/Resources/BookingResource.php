<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the `Booking` interface in mobile/src/types/api.ts field for field.
 *
 * The row carries a lot more than this: full street addresses, both contact
 * phone numbers, carrier/driver/truck assignment, distance, deposit, internal
 * cancellation reason. The customer's own booking detail screen renders city and
 * state only, so that is all that crosses the boundary -- and the carrier
 * assignment in particular is commercially sensitive (§4.12).
 *
 * @mixin \App\Models\Booking
 */
class BookingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'booking_number' => $this->booking_number,
            'status' => $this->status->value,
            'service' => $this->summariseService($this->service),

            'pickup_city' => $this->pickup_city,
            'pickup_state' => $this->pickup_state,
            'dropoff_city' => $this->dropoff_city,
            'dropoff_state' => $this->dropoff_state,

            // Planned legs are date-only columns; the actuals are timestamps. The
            // client formats them differently, so the difference is preserved.
            'scheduled_pickup_date' => $this->scheduled_pickup_date?->toDateString(),
            'scheduled_delivery_date' => $this->scheduled_delivery_date?->toDateString(),
            'actual_pickup_at' => $this->actual_pickup_at?->toIso8601String(),
            'actual_delivery_at' => $this->actual_delivery_at?->toIso8601String(),

            'total_price' => $this->total_price,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balance_due,

            // Server-computed: delivered AND not already reviewed. The client must
            // never derive this from status alone or it offers a second review.
            'can_review' => $this->canBeReviewed(),

            /**
             * Optional in api.ts, so the key is absent unless the caller loaded
             * the relation. The visibility filter is re-applied here rather than
             * trusted to the query: a caller that eager-loads `events`
             * unconstrained would otherwise publish dispatch's internal notes
             * (§4.8). Filtering in memory costs nothing on a loaded relation.
             */
            'events' => $this->whenLoaded('events', fn () => BookingEventResource::collection(
                $this->events->where('is_customer_visible', true)->values()
            )),
        ];
    }

    /**
     * api.ts types `service` as a non-optional key that may be null, so it is
     * always emitted. Controllers eager-load the relation (the bookings list is
     * the app's hottest query); a caller that forgot pays for one lazy read
     * rather than shipping a payload the client cannot parse.
     *
     * @return array<string, mixed>|null
     */
    private function summariseService(?Service $service): ?array
    {
        if ($service === null) {
            return null;
        }

        return [
            'id' => (int) $service->id,
            'name' => $service->name,
            'slug' => $service->slug,
        ];
    }
}
