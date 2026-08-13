<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the `VehicleType` interface in mobile/src/types/api.ts.
 *
 * price_multiplier is public on purpose: the app shows "SUVs cost 15% more"
 * before the form is submitted, and the number is worthless to an attacker --
 * the server recomputes every estimate from its own rows.
 *
 * @mixin \App\Models\VehicleType
 */
class VehicleTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'size_class' => $this->size_class,
            // decimal:3 reads back as '1.150'; api.ts types it number.
            'price_multiplier' => (float) $this->price_multiplier,
        ];
    }
}
