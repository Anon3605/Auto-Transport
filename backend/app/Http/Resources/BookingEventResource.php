<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the `BookingEvent` interface in mobile/src/types/api.ts.
 *
 * Four columns on the row are missing here and that is the whole point:
 * `is_customer_visible` (the filter itself, useless once applied),
 * `from_status` (the client already holds the previous item in the timeline),
 * `created_by` (naming the dispatcher who touched a shipment is internal), and
 * `meta` (free-form operational payload -- carrier rates, gateway ids). Design
 * doc §4.8: dispatch records internal chatter on this same table.
 *
 * @mixin \App\Models\BookingEvent
 */
class BookingEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'event_type' => $this->event_type,
            // Left as the raw string: from_status/to_status are not enum-cast on
            // the model, and a location_ping carries no status at all.
            'to_status' => $this->to_status,
            'description' => $this->description,
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            // occurred_at, not created_at -- an offline driver ping is timestamped
            // when it happened, and the tracking screen orders on this.
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
