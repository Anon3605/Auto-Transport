<?php

namespace App\Http\Requests\Api;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * A burst backstop sitting deliberately above AuthController's 5-failure
     * account lockout: a human attacking one account trips the lockout first and
     * gets told so, while a script hammering the endpoint still hits a wall.
     */
    public const MAX_ATTEMPTS = 10;

    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // No format rules on the way in: a rejected password shape tells an
            // attacker what our policy is, and legacy passwords predate it.
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * Keyed on email AND ip: keying on email alone lets one attacker lock every
     * customer out, keying on ip alone lets a whole office share one budget.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        // 422 rather than 429 so the message lands in the client's error bag on
        // the email field, next to the input the user has to change.
        throw ValidationException::withMessages([
            'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
        ]);
    }
}
