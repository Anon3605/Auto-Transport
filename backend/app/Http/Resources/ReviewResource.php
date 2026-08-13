<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the Review interface in mobile/src/types/api.ts field for field.
 *
 * Nothing else may be added here. The customer's full name, e-mail, IP address,
 * moderator id and rejection reason all live on the same row, and this resource
 * is served on public marketing surfaces -- author_name is the deliberately
 * lossy projection (see Review::authorName()).
 *
 * @mixin \App\Models\Review
 */
class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            /*
             * The shipment is referenced by ULID, never by the integer id (§4.5).
             * This resource is also what renders the PUBLIC reviews list on a
             * service page, where a sequential booking id would hand any visitor
             * a running count of company volume -- so it is emitted only when the
             * caller already had the booking loaded, which is the owner's own
             * "my review" view and never the public listing.
             */
            'booking_ulid' => $this->whenLoaded('booking', fn (): ?string => $this->booking?->ulid),

            'rating_overall' => (int) $this->rating_overall,
            'rating_communication' => $this->rating_communication,
            'rating_timeliness' => $this->rating_timeliness,
            'rating_condition' => $this->rating_condition,
            'rating_value' => $this->rating_value,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'admin_reply' => $this->admin_reply,
            'helpful_count' => (int) $this->helpful_count,
            'author_name' => $this->author_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
