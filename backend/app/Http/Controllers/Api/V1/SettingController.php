<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * GET /settings/public -- the company phone number, map centre, deposit
 * percentage and the rest of the config the app needs before it can render
 * anything (§6).
 */
class SettingController extends Controller
{
    /**
     * Setting::publicMap() filters to is_public AND drops encrypted values, so an
     * integration secret accidentally flagged public still cannot leak here. The
     * map is cached forever and invalidated by any Setting write.
     *
     * Returned as a bare { group: { key: value } } object rather than through a
     * resource: the shape is arbitrary config, not a modelled entity, and the
     * client's unwrap() reads it either way.
     */
    public function index(): JsonResponse
    {
        // Cast to object so an empty map serialises as {} and not [] -- the client
        // reads this as a keyed record, and [] breaks that type on a fresh install.
        return response()->json(['data' => (object) Setting::publicMap()]);
    }

    /** Alias so the route may bind this controller as a single action. */
    public function __invoke(): JsonResponse
    {
        return $this->index();
    }
}
