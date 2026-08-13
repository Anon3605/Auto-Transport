<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published FAQ. `category` is emitted rather than the rows being grouped
 * server-side: the client renders sections in the order the list arrives (the
 * query is already sorted by category, then sort_order), and a grouped object
 * would lose that ordering the moment it hits JSON.
 *
 * @mixin \App\Models\Faq
 */
class FaqResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'category' => $this->category,
            'question' => $this->question,
            'answer' => $this->answer,
        ];
    }
}
