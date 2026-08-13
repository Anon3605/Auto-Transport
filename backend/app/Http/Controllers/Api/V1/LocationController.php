<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Terminals, offices and coverage points. Feeds the coverage list and the
 * Contact-page map, whose centre is the is_primary row (§6).
 */
class LocationController extends Controller
{
    /**
     * Primary first so the client can centre the map on head(), then
     * alphabetical. The locations table has no sort_order column to honour.
     */
    public function index(): AnonymousResourceCollection
    {
        return LocationResource::collection(
            Location::query()
                ->active()
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get()
        );
    }
}
