<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The saved address book. Field names match the `AddressInput` shape in
 * mobile/src/types/api.ts so a saved row can be dropped straight into the quote
 * form without a translation layer.
 *
 * user_id is deliberately absent: the collection is already scoped to the
 * authenticated user, so echoing the owner adds nothing but an internal id.
 *
 * @mixin \App\Models\Address
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
            // decimal casts hand back strings; the client's lat/lng are numbers.
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            'location_type' => $this->location_type,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
