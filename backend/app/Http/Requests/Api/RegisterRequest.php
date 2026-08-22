<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Public registration.
 *
 * SELF-SERVICE ROLE SELECTION IS A PRIVILEGE-ESCALATION SURFACE, so the choice
 * is a whitelist of exactly two values rather than a role name the caller
 * supplies. Accepting an arbitrary role here — or trusting a `roles` array from
 * the body — would let anyone register themselves as `super-admin`. Staff
 * accounts (admin, dispatcher, support) are created by staff in the panel and
 * have no public path at all.
 *
 * A driver additionally has to declare their licence up front. Those fields are
 * required only for a driver, which is what `required_if` expresses: a customer
 * submitting the same form must not be asked for a CDL class.
 */
class RegisterRequest extends FormRequest
{
    /** The only account types anyone may create for themselves. */
    public const SELF_SERVICE_TYPES = ['customer', 'driver'];

    protected function prepareForValidation(): void
    {
        /*
         * Email is lower-cased and trimmed BEFORE the unique rule runs, so
         * "DANA@example.com" collides with an existing "dana@example.com".
         * Without this the unique check is case-sensitive on any collation that
         * is, and two accounts differing only in capitals both register — then
         * neither person can work out why login sometimes fails.
         */
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,

            // Absent means customer: the overwhelmingly common case, and it keeps
            // the existing client contract working unchanged.
            'account_type' => $this->input('account_type', 'customer'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::in(self::SELF_SERVICE_TYPES)],

            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Length plus two character classes. No max: a passphrase from a
            // manager should never be truncated by our rules.
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],

            /*
             * Optional for a customer, required for a driver. Dispatch has to be
             * able to reach the person carrying the vehicle on the day, and a
             * driver with no number is an operational dead end.
             */
            'phone' => [
                Rule::requiredIf(fn (): bool => $this->input('account_type') === 'driver'),
                'nullable',
                'string',
                'max:32',
            ],

            'device_name' => ['nullable', 'string', 'max:120'],

            // --- driver only ------------------------------------------------
            'license_number' => ['required_if:account_type,driver', 'nullable', 'string', 'max:64'],
            'license_state' => ['required_if:account_type,driver', 'nullable', 'string', 'max:64'],

            /*
             * after:today, not just a date. An expired licence is not a typo to be
             * accepted and sorted out later — it is the one fact that makes the
             * applicant unable to do the job today.
             */
            'license_expires_at' => ['required_if:account_type,driver', 'nullable', 'date', 'after:today'],

            // Commercial licence classes. Free text here becomes unqueryable the
            // first time somebody types "class a" instead of "A".
            'cdl_class' => ['required_if:account_type,driver', 'nullable', Rule::in(['A', 'B', 'C', 'none'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_type.in' => 'Choose either a customer or a driver account.',
            'phone.required' => 'A phone number is required for a driver account — dispatch has to reach you on the day.',
            'license_number.required_if' => 'Enter your licence number.',
            'license_state.required_if' => 'Which state or region issued the licence?',
            'license_expires_at.required_if' => 'Enter the licence expiry date.',
            'license_expires_at.after' => 'That licence has already expired.',
            'cdl_class.required_if' => 'Select your licence class.',
        ];
    }

    public function isDriverApplication(): bool
    {
        return $this->input('account_type') === 'driver';
    }

    /** @return array<string, mixed> */
    public function driverProfileAttributes(): array
    {
        return [
            'license_number' => $this->input('license_number'),
            'license_state' => $this->input('license_state'),
            'license_expires_at' => $this->input('license_expires_at'),
            'cdl_class' => $this->input('cdl_class'),
        ];
    }
}
