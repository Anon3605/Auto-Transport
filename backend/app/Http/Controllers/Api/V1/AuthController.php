<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token authentication for the React Native client. Stateless by design: the
 * app holds a Sanctum personal access token in the device keystore, so nothing
 * here touches a session or a cookie.
 */
class AuthController extends Controller
{
    /** Consecutive wrong passwords before the account itself is locked, not just throttled. */
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    /** Used when the client sends no device_name -- it becomes the token label. */
    private const DEFAULT_DEVICE_NAME = 'mobile';

    /**
     * One answer for every address: see forgotPassword().
     */
    private const RESET_LINK_MESSAGE = 'If an account exists for that address, a password reset link is on its way.';

    public function register(RegisterRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $user = $registerUser($request->safe()->only(['name', 'email', 'password', 'phone']));

        // Signed in immediately. Email verification gates what the account may
        // inherit (§4.10), not whether it may be used.
        return $this->tokenResponse($user, $request, Response::HTTP_CREATED);
    }

    /**
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::query()->where('email', $request->validated('email'))->first();

        // Ahead of the hash comparison: no password opens a locked account, so
        // checking one only spends bcrypt time an attacker gets to choose.
        if ($user?->isLocked()) {
            return $this->lockedResponse($user);
        }

        if ($user === null || ! Hash::check((string) $request->validated('password'), $user->password)) {
            RateLimiter::hit($request->throttleKey(), LoginRequest::DECAY_SECONDS);

            $this->recordFailedAttempt($user);

            // The attempt that trips the lock says so, rather than sending the
            // human back to burn a sixth try.
            if ($user?->isLocked()) {
                return $this->lockedResponse($user);
            }

            // Same message for "no such user" and "wrong password" -- this
            // endpoint is not an account-enumeration oracle.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->status !== 'active') {
            // 403, not 422: the credentials were right, the account is not
            // usable. Nothing the client can fix by editing the form.
            return response()->json([
                'message' => 'This account is not active. Contact support to have it reinstated.',
            ], Response::HTTP_FORBIDDEN);
        }

        RateLimiter::clear($request->throttleKey());

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $this->tokenResponse($user, $request, Response::HTTP_OK);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->loadMissing('roles')),
        ]);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        // Only this device's token dies: signing out on a phone must not sign the
        // same customer out of their tablet. A TransientToken (cookie session
        // from a stateful domain) has no row to delete.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->configureResetUrl();

        // The broker's INVALID_USER and RESET_THROTTLED statuses are both
        // swallowed on purpose. A different answer for a known address turns this
        // endpoint into a membership test against any leaked email list.
        Password::sendResetLink($request->validated());

        return response()->json(['message' => self::RESET_LINK_MESSAGE]);
    }

    private function tokenResponse(User $user, Request $request, int $status): JsonResponse
    {
        // The label is what the customer will read in a "signed-in devices"
        // list, so it comes from the client instead of being invented here.
        $device = trim((string) $request->input('device_name'));

        return response()->json([
            'token' => $user->createToken($device !== '' ? $device : self::DEFAULT_DEVICE_NAME)->plainTextToken,
            'user' => new UserResource($user->loadMissing('roles')),
        ], $status);
    }

    /**
     * failed_login_count exists to make a slow, distributed guessing run against
     * one known address expensive; the request throttle only ever sees a single
     * IP's share of it.
     */
    private function recordFailedAttempt(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $failures = (int) $user->failed_login_count + 1;

        // The counter restarts instead of climbing past the threshold, so five
        // guesses buy one 15-minute lock rather than stacking them.
        $user->forceFill($failures >= self::MAX_FAILED_ATTEMPTS
            ? ['failed_login_count' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
            : ['failed_login_count' => $failures])->save();
    }

    private function lockedResponse(User $user): JsonResponse
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until, absolute: true) / 60));

        return response()->json([
            'message' => 'Too many failed sign-in attempts. This account is locked for another '
                .$minutes.' '.Str::plural('minute', $minutes).'.',
        ], Response::HTTP_LOCKED);
    }

    /**
     * The stock notification builds its link from route('password.reset'), and
     * this host serves no such route -- the reset screen lives in the app. Point
     * the URL at the public site so sending cannot fail on a missing route, while
     * still yielding to a real named route if the web side ever grows one.
     */
    private function configureResetUrl(): void
    {
        if (Route::has('password.reset')) {
            return;
        }

        ResetPassword::createUrlUsing(fn (User $notifiable, string $token): string => rtrim((string) config('app.url'), '/')
            .'/reset-password?token='.$token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset()));
    }
}
