<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * `ulid` is absent on purpose: HasUlid fills it on the creating event, which
     * also covers rows created by anything other than this factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // The set mutator on `phone` writes phone_normalized too, so factory
            // users are findable by number the same way real signups are.
            'phone' => fake()->numerify('+1##########'),
            'locale' => 'en',
            'timezone' => 'America/Chicago',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /** A locked-out account: failed_login_count tripped the throttle. */
    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'failed_login_count' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);
    }

    /**
     * findOrCreate rather than findByName: a feature test that only ran migrations
     * and never RolePermissionSeeder still ends up with a user whose isStaff() and
     * roles[] answers mean something. Permissions are not granted here -- tests that
     * assert on a permission should seed the roles properly.
     */
    public function withRole(UserRole|string $role): static
    {
        $name = $role instanceof UserRole ? $role->value : $role;

        return $this->afterCreating(function (User $user) use ($name): void {
            $user->assignRole(Role::findOrCreate($name, 'web'));
        });
    }

    public function superAdmin(): static
    {
        return $this->withRole(UserRole::SuperAdmin);
    }

    public function admin(): static
    {
        return $this->withRole(UserRole::Admin);
    }

    public function dispatcher(): static
    {
        return $this->withRole(UserRole::Dispatcher);
    }

    public function support(): static
    {
        return $this->withRole(UserRole::Support);
    }

    public function driver(): static
    {
        return $this->withRole(UserRole::Driver);
    }

    public function customer(): static
    {
        return $this->withRole(UserRole::Customer);
    }
}
