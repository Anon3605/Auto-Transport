<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Staff-created accounts. The route already carries the manage_users
 * permission; what this class adds is the privilege-escalation guard on
 * `roles` -- hiding the super-admin checkbox in Blade is cosmetic, a hand-built
 * POST walks straight past it.
 */
class StoreUserRequest extends FormRequest
{
    /** users.status is a VARCHAR with no enum behind it; this is the whole domain. */
    public const STATUSES = ['active', 'suspended', 'pending'];

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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],

            // Password::defaults() is set once in AppServiceProvider, so "strong
            // enough" has one definition across the panel and the API. No max
            // length: a manager-generated passphrase must not be truncated.
            'password' => ['required', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'string', 'timezone', 'max:64'],
            'status' => ['required', Rule::in(self::STATUSES)],

            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name'), $this->superAdminGuard()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'That address already belongs to an account, possibly a deleted one.',
        ];
    }

    /**
     * An admin vouching for the address is the verification: there is no
     * confirmation round-trip on a staff-created account, and the locale /
     * timezone columns are NOT NULL with defaults, so an omitted field has to
     * fall back rather than write null.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
            'password' => $this->validated('password'),
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
            // SQLite compares strings case-sensitively, so "Dana@x.com" and
            // "dana@x.com" would otherwise become two accounts no support agent
            // could tell apart.
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }

        $this->merge(['roles' => self::normalizeRoles($this->input('roles'))]);
    }

    /**
     * Accepts role names or role ids from the form and always hands syncRoles()
     * names, so the Blade checkboxes may use either without the guard below
     * losing sight of what 'super-admin' looks like.
     *
     * @return list<string>
     */
    public static function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        $names = [];

        foreach ($roles as $role) {
            if (is_numeric($role)) {
                $name = Role::query()->whereKey((int) $role)->value('name');

                if ($name !== null) {
                    $names[] = $name;
                }

                continue;
            }

            if (is_string($role) && $role !== '') {
                $names[] = $role;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Only a super-admin may mint another one. A validation rule rather than a
     * controller check so the attempt comes back in the form's error bag instead
     * of being silently dropped.
     */
    protected function superAdminGuard(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== UserRole::SuperAdmin->value) {
                return;
            }

            if ($this->user()?->hasRole(UserRole::SuperAdmin->value) !== true) {
                $fail('Only a super-admin can grant the super-admin role.');
            }
        };
    }
}
