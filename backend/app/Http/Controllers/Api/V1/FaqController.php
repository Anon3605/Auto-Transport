<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FaqController extends Controller
{
    /**
     * Ordered by category then sort_order (Faq::ordered), so the client can build
     * its accordion sections by walking the list once. faqs_listing_idx covers
     * the active + category + sort_order shape of this query.
     */
    public function index(): AnonymousResourceCollection
    {
        return FaqResource::collection(
            Faq::query()->active()->ordered()->get()
        );
    }
}
