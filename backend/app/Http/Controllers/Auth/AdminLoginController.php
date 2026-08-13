<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for the Blade admin panel. The RN client authenticates against
 * /api/v1 with Sanctum tokens and never touches this controller.
 */
class AdminLoginController extends Controller
{
    /** Consecutive failures before the account itself is locked, not just throttled. */
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function show(): View
    {
        return view('admin.auth.login');
    }

    /**
     * Credentials are checked by hand rather than through Auth::attempt() so a
     * customer's correct password never opens a panel session, not even for the
     * microsecond between attempt() and a logout() -- the session id is issued
     * before we would get to look at the roles.
     *
     * @throws ValidationException
     */
    public function attempt(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->recordFailure($user);

            // One message for "no such user" and "wrong password" -- the login
            // form is not an account-enumeration oracle.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => 'This account is temporarily locked. Try again later or contact an administrator.',
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => 'This account is not active.',
            ]);
        }

        if (! $user->isStaff()) {
            throw ValidationException::withMessages([
                'email' => 'This account does not have admin access.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        // Fixation defence: the pre-login session id must not survive the
        // privilege change.
        $request->session()->regenerate();

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'You have been signed out.');
    }

    /**
     * failed_login_count and locked_until exist to make a slow, distributed
     * guessing run against one known admin address expensive; the route
     * throttle only sees a single IP at a time.
     */
    private function recordFailure(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $failures = (int) $user->failed_login_count + 1;

        $user->forceFill($failures >= self::MAX_FAILED_ATTEMPTS
            ? ['failed_login_count' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
            : ['failed_login_count' => $failures])->save();
    }
}
