<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ContactMessageController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DriverJobController;
use App\Http\Controllers\Api\V1\FaqController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\QuoteRequestController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\VehicleTypeController;
use Spatie\Permission\Middleware\RoleMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| The path strings here are the server half of a contract whose client half is
| mobile/src/api/endpoints.ts -- that file is the single source of truth for the
| RN app, so a rename here without a rename there is a silent 404 at runtime.
| Keep the two in step.
|
| Every {param} binds on a ULID or a slug, never an auto-increment id
| (docs/database-design.md 4.5). The models declare that through
| getRouteKeyName(), so the plain {booking} form below already resolves on
| `ulid` and {service} on `slug`.
|
| An unguessable key is NOT authorization. Every owner-scoped route still runs
| its policy check.
*/

Route::prefix('v1')->group(function (): void {

    /*
     * ---------------------------------------------------------------- public
     * Catalog and content: no auth, safe for a guest browsing the marketing
     * surface before deciding whether to sign up.
     */
    Route::get('services', [ServiceController::class, 'index']);
    Route::get('services/{service}', [ServiceController::class, 'show']);
    Route::get('vehicle-types', [VehicleTypeController::class, 'index']);
    Route::get('locations', [LocationController::class, 'index']);
    Route::get('faqs', [FaqController::class, 'index']);
    Route::get('settings/public', [SettingController::class, 'index']);

    // Approved reviews for one service. Public by design -- this is the social
    // proof the marketing pages are built around.
    Route::get('services/{service}/reviews', [ReviewController::class, 'index']);

    /*
     * Instant estimate: unauthenticated and not persisted. Requiring a signup
     * before showing a price is the conversion killer 4.10 warns about.
     * Throttled because it is free compute that fans out to a paid distance API.
     */
    Route::post('quotes/estimate', [QuoteRequestController::class, 'estimate'])
        ->middleware('throttle:20,1');

    // Guests may submit a real request; user_id stays null and is claimed later,
    // only after email verification (4.10).
    Route::post('quote-requests', [QuoteRequestController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::post('contact-messages', [ContactMessageController::class, 'store'])
        ->middleware('throttle:5,1');

    /*
     * ------------------------------------------------------------------ auth
     * Tight per-IP throttles: this is the credential-stuffing surface and there
     * is no CAPTCHA in front of it.
     */
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

        // Answers 200 whether or not the address exists, so the response cannot
        // be used to enumerate which emails are registered.
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:3,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    /*
     * --------------------------------------------------------------- private
     * Sanctum bearer tokens, read from SecureStore by the RN client. Everything
     * below is scoped to the caller; the policies hold the ownership rules.
     */
    Route::middleware('auth:sanctum')->group(function (): void {

        /*
         * PATCH and PUT both land here. These are genuinely partial updates --
         * the client sends only the fields the user touched and the controller
         * ignores anything it does not own (changing an email is an identity
         * change, not a profile edit) -- so PATCH is the honest verb. PUT is
         * accepted alongside it because it is the reflex for anyone hand-testing
         * with curl, and a 405 is a confusing way to learn the difference.
         */
        Route::get('profile', [ProfileController::class, 'show']);
        Route::match(['put', 'patch'], 'profile', [ProfileController::class, 'update']);

        // Bound on the auto-increment id, not a ULID: addresses are only ever
        // reachable through the owner's own collection, which the controller
        // scopes before touching the row. A stranger's id 404s rather than 403s,
        // so the response does not confirm the row exists.
        Route::get('profile/addresses', [ProfileController::class, 'addresses']);
        Route::post('profile/addresses', [ProfileController::class, 'storeAddress']);
        Route::match(['put', 'patch'], 'profile/addresses/{address}', [ProfileController::class, 'updateAddress']);
        Route::delete('profile/addresses/{address}', [ProfileController::class, 'destroyAddress']);

        // "My quotes" -- same path as the guest POST above, different verb.
        Route::get('quote-requests', [QuoteRequestController::class, 'index']);
        Route::get('quote-requests/{quoteRequest}', [QuoteRequestController::class, 'show']);

        /*
         * Book a service straight from the app. Writes the full
         * quote_request -> quote -> booking chain, so an instantly-booked
         * shipment keeps the intake and offer history every other booking has.
         * Throttled: it is a write that creates four rows and costs a distance
         * lookup.
         */
        Route::post('bookings', [BookingController::class, 'store'])
            ->middleware('throttle:10,1');

        Route::get('bookings', [BookingController::class, 'index']);
        Route::get('bookings/{booking}', [BookingController::class, 'show']);
        Route::get('bookings/{booking}/events', [BookingController::class, 'events']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);

        /*
         * The post-service review. Anchored to a delivered booking the caller
         * owns; the UNIQUE booking_id index is the final backstop if a second
         * submission races the controller's check (4.7).
         */
        Route::post('reviews', [ReviewController::class, 'store']);
        Route::post('reviews/{review}/helpful', [ReviewController::class, 'helpful']);

        Route::post('device-tokens', [DeviceTokenController::class, 'store']);

        /*
         * ------------------------------------------------------------- driver
         * A driver is not a customer. The customer endpoints above scope on
         * `user_id`; these scope on `driver_id`, which is the whole reason they
         * exist as a separate group rather than a filter on /bookings.
         *
         * Gated by the `driver` role: a customer holding a valid token must not be
         * able to enumerate loads by calling a driver endpoint.
         */
        Route::middleware(RoleMiddleware::using('driver'))->prefix('driver')->group(function (): void {
            Route::get('jobs', [DriverJobController::class, 'index']);
            Route::get('jobs/{booking}', [DriverJobController::class, 'show']);
            Route::post('jobs/{booking}/status', [DriverJobController::class, 'updateStatus']);
        });
    });
});
