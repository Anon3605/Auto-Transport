<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors the `Service` interface in mobile/src/types/api.ts field for field, and
 * is used for both the listing and the detail endpoint so the client parses one
 * shape everywhere.
 *
 * min_price_cents is deliberately absent: it is an input to the estimator (§7),
 * and publishing the floor price invites "why is my quote higher than your
 * minimum" arguments. The long `description` is absent because the client type
 * has no field for it -- add it to api.ts first, not here.
 *
 * @mixin \App\Models\Service
 */
class ServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // api.ts types this as a number: services have no ulid column and
            // endpoints.ts addresses them by slug, so the integer id is only ever
            // an opaque handle the client echoes back on a quote request.
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'icon' => $this->icon,
            'hero_image_url' => $this->hero_image_path
                ? url(Storage::disk()->url($this->hero_image_path))
                : null,
            // Money accessors already emit { cents, currency } (§4.4).
            'base_price' => $this->base_price,
            'price_per_mile' => $this->price_per_mile,
            'transit_days_min' => $this->transit_days_min,
            'transit_days_max' => $this->transit_days_max,
            // A decimal:2 cast reads back as the string '4.50'; api.ts says number.
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => (int) $this->rating_count,
        ];
    }
}
