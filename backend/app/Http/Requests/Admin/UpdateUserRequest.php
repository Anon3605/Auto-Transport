<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Edits an existing account, including the suspend / reactivate flip -- status
 * is an ordinary field on this form rather than a separate endpoint, because
 * "suspend" is one column and a bespoke route would need the same guards.
 *
 * The escalation guard from StoreUserRequest is repeated here on purpose: an
 * account created as a customer and later promoted is the same hole.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],

            'email' => [
                'required',
                'email',
                'max:255',
                // Soft-deleted rows keep their address, so the ignore() is scoped
                // to this id only and a collision with a trashed account still fails.
                Rule::unique('users', 'email')->ignore($this->target()?->getKey()),
            ],

            // Optional on update: an empty box means "leave the current hash
            // alone", which is why the controller only touches password when a
            // value actually arrives. Strength comes from Password::defaults(),
            // set once in AppServiceProvider.
            'password' => ['nullable', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'string', 'timezone', 'max:64'],
            'status' => ['required', Rule::in(StoreUserRequest::STATUSES)],

            'roles' => ['array'],

            /*
             * Driver profile. Only meaningful when the user holds the driver role;
             * the controller ignores these otherwise rather than creating an
             * orphan profile for a customer.
             *
             * carrier_id is the approval switch: a driver with no employer cannot
             * be assigned work, because AssignBookingRequest checks employment.
             */
            'driver.carrier_id' => ['nullable', 'integer', Rule::exists('carriers', 'id')->whereNull('deleted_at')],
            'driver.license_number' => ['nullable', 'string', 'max:64'],
            'driver.license_state' => ['nullable', 'string', 'max:64'],
            'driver.license_expires_at' => ['nullable', 'date'],
            'driver.cdl_class' => ['nullable', Rule::in(['A', 'B', 'C', 'none'])],
            'driver.is_available' => ['boolean'],
            'roles.*' => ['string', Rule::exists('roles', 'name'), $this->superAdminGuard()],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->target();

                if ($target === null || $this->user()?->getKey() !== $target->getKey()) {
                    return;
                }

                // Locking yourself out of the panel is never the intent, and
                // recovering from it needs a database console.
                if ($this->input('status') !== 'active') {
                    $validator->errors()->add('status', 'You cannot suspend your own account.');
                }
            },
        ];
    }

    /**
     * Attributes for a mass assignment. `password` is deliberately absent --
     * the controller adds it only when one was supplied, so a blank field can
     * never overwrite a working hash with an empty one.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
            'phone' => $this->validated('phone'),
            'locale' => $this->validated('locale') ?: 'en',
            'timezone' => $this->validated('timezone') ?: 'UTC',
            'status' => $this->validated('status'),
        ];
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_values(array_unique($this->validated('roles') ?? []));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }

        $this->merge(['roles' => StoreUserRequest::normalizeRoles($this->input('roles'))]);
    }

    /** The user being edited, resolved by SubstituteBindings on the {user} ulid. */
    private function target(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }

    protected function superAdminGuard(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== UserRole::SuperAdmin->value) {
                return;
            }

            // Re-saving a super-admin's own profile must not trip the guard on
            // the role they already hold.
            if ($this->target()?->hasRole(UserRole::SuperAdmin->value) === true) {
                return;
            }

            if ($this->user()?->hasRole(UserRole::SuperAdmin->value) !== true) {
                $fail('Only a super-admin can grant the super-admin role.');
            }
        };
    }
}
