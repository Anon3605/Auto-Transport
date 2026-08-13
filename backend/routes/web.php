<?php

use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\QuoteRequestController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AdminLoginController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Laravel's auth middleware resolves route('login') when it turns an
 * AuthenticationException into a redirect. The panel is the only session-auth
 * surface on this host -- the RN app talks to /api/v1 with Sanctum tokens -- so
 * point that well-known name at it rather than leave guests on a 500.
 */
Route::redirect('/login', '/admin/login')->name('login');

/*
 * Admin panel. Server-rendered Blade, session guard, no build step.
 *
 * Two layers of access control, and both are load-bearing:
 *   'staff'      is the coarse gate -- authenticated is not the same as employed;
 *   permission:* is what stops a support agent reaching settings while still
 *                letting them work the review queue.
 *
 * The permission middleware is referenced as a class rather than through the
 * 'permission' alias so these routes hold regardless of what bootstrap/app.php
 * happens to alias. The names are the ones RolePermissionSeeder creates.
 */
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('login', [AdminLoginController::class, 'show'])->name('login');

        // The panel is the highest-value credential target on the domain and
        // there is no CAPTCHA in front of it.
        Route::post('login', [AdminLoginController::class, 'attempt'])
            ->middleware('throttle:6,1')
            ->name('login.attempt');
    });

    Route::middleware(['auth', 'staff'])->group(function (): void {
        // POST only -- a GET logout is CSRF-able from an <img> tag.
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');

        // Open to any staff member: the tiles are gated individually, so the
        // dashboard shows what the viewer is allowed to open and nothing else.
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::prefix('users')->name('users.')->group(function (): void {
            Route::get('/', [UserController::class, 'index'])
                ->middleware(PermissionMiddleware::using('view_users'))->name('index');

            // Ahead of {user}, or "create" is read as a route key.
            Route::get('create', [UserController::class, 'create'])
                ->middleware(PermissionMiddleware::using('manage_users'))->name('create');

            Route::post('/', [UserController::class, 'store'])
                ->middleware(PermissionMiddleware::using('manage_users'))->name('store');

            Route::get('{user}', [UserController::class, 'show'])
                ->middleware(PermissionMiddleware::using('view_users'))->name('show');

            Route::get('{user}/edit', [UserController::class, 'edit'])
                ->middleware(PermissionMiddleware::using('manage_users'))->name('edit');

            Route::put('{user}', [UserController::class, 'update'])
                ->middleware(PermissionMiddleware::using('manage_users'))->name('update');

            Route::delete('{user}', [UserController::class, 'destroy'])
                ->middleware(PermissionMiddleware::using('manage_users'))->name('destroy');
        });

        // The grant matrix rides on manage_users: editing what a role may do is
        // the same power as deciding who holds it.
        Route::prefix('roles')->name('roles.')
            ->middleware(PermissionMiddleware::using('manage_users'))
            ->group(function (): void {
                Route::get('/', [RoleController::class, 'index'])->name('index');
                Route::put('{role}', [RoleController::class, 'update'])->name('update');
            });

        Route::prefix('reviews')->name('reviews.')->group(function (): void {
            Route::get('/', [ReviewController::class, 'index'])
                ->middleware(PermissionMiddleware::using('view_reviews'))->name('index');

            Route::get('{review}', [ReviewController::class, 'show'])
                ->middleware(PermissionMiddleware::using('view_reviews'))->name('show');

            // Publishing is a stronger right than reading the queue.
            Route::middleware(PermissionMiddleware::using('moderate_reviews'))->group(function (): void {
                Route::post('{review}/approve', [ReviewController::class, 'approve'])->name('approve');
                Route::post('{review}/reject', [ReviewController::class, 'reject'])->name('reject');
                Route::post('{review}/reply', [ReviewController::class, 'reply'])->name('reply');
                Route::post('{review}/feature', [ReviewController::class, 'feature'])->name('feature');
            });
        });

        Route::prefix('bookings')->name('bookings.')->group(function (): void {
            Route::get('/', [BookingController::class, 'index'])
                ->middleware(PermissionMiddleware::using('view_bookings'))->name('index');

            Route::get('{booking}', [BookingController::class, 'show'])
                ->middleware(PermissionMiddleware::using('view_bookings'))->name('show');

            // A support agent may look up a shipment; only dispatch may move it.
            Route::post('{booking}/status', [BookingController::class, 'status'])
                ->middleware(PermissionMiddleware::using('manage_bookings'))->name('status');
        });

        // Leads are read-only here: quote_requests is the intake record (§4.1) and
        // pricing one is the quote API's job, not a form on a listing page.
        Route::prefix('quote-requests')->name('quotes.')
            ->middleware(PermissionMiddleware::using('view_quotes'))
            ->group(function (): void {
                Route::get('/', [QuoteRequestController::class, 'index'])->name('index');
                Route::get('{quoteRequest}', [QuoteRequestController::class, 'show'])->name('show');
            });

        Route::prefix('messages')->name('messages.')->group(function (): void {
            Route::get('/', [ContactMessageController::class, 'index'])
                ->middleware(PermissionMiddleware::using('view_contact_messages'))->name('index');

            Route::get('{message}', [ContactMessageController::class, 'show'])
                ->middleware(PermissionMiddleware::using('view_contact_messages'))->name('show');

            Route::post('{message}/reply', [ContactMessageController::class, 'reply'])
                ->middleware(PermissionMiddleware::using('manage_contact_messages'))->name('reply');
        });

        Route::prefix('services')->name('services.')
            ->middleware(PermissionMiddleware::using('manage_content'))
            ->group(function (): void {
                Route::get('/', [ServiceController::class, 'index'])->name('index');
                Route::get('{service}/edit', [ServiceController::class, 'edit'])->name('edit');
                Route::put('{service}', [ServiceController::class, 'update'])->name('update');
            });

        Route::prefix('settings')->name('settings.')
            ->middleware(PermissionMiddleware::using('manage_settings'))
            ->group(function (): void {
                Route::get('/', [SettingController::class, 'index'])->name('index');
                Route::put('/', [SettingController::class, 'update'])->name('update');
            });
    });
});
