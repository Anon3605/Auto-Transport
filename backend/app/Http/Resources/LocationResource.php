<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Terminals and offices for the coverage list and the Contact-page map. api.ts
 * has no Location interface yet; this shape is the one to add there.
 *
 * lat/lng are the reason this endpoint exists (the map centres on the primary
 * row), so they are emitted as floats rather than the strings a decimal cast
 * hands back -- a map library given "27.9506" places nothing.
 *
 * @mixin \App\Models\Location
 */
class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            'phone' => $this->phone,
            'email' => $this->email,
            'hours' => $this->hours,
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
