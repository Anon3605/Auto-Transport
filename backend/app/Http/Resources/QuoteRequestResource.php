<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the `QuoteRequest` interface in mobile/src/types/api.ts.
 *
 * This is the receipt for a submitted lead, not the lead itself: the client
 * already has everything it typed, so echoing the addresses, contact details and
 * VINs back would only widen the blast radius of a mis-scoped query. spam_score,
 * assignee and the internal notes stay server-side entirely.
 *
 * estimated_price is the §7 auto-estimate and is explicitly NOT a quote -- the
 * binding number arrives later as a `quotes` row.
 *
 * @mixin \App\Models\QuoteRequest
 */
class QuoteRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'reference' => $this->reference,
            'status' => $this->status->value,
            // Money | null: null until the estimator has priced the lane.
            'estimated_price' => $this->estimated_price,
            'distance_miles' => $this->distance_miles === null ? null : (int) $this->distance_miles,
            'vehicle_count' => (int) $this->vehicle_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
