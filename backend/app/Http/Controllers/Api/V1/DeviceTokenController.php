<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Push registration for the Expo app. Called on every launch once permission is
 * granted, which is why it is an upsert and returns no body -- the client has
 * nothing to do with the row, and re-registering must be idempotent.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'provider' => ['nullable', 'string', 'in:expo,fcm,apns'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        /**
         * registerFor() matches on sha256(token), so a device that changes hands
         * is REASSIGNED to the new account rather than duplicated -- otherwise the
         * previous owner keeps receiving this phone's shipment notifications.
         */
        DeviceToken::registerFor($request->user(), $validated);

        return response()->noContent();
    }
}
