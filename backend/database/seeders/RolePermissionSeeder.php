<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Access control baseline (design doc §6.1 and §6.2).
 *
 * One permission per admin resource, grouped by the `group` column the identity
 * migration added so the admin panel can render the grant matrix as labelled
 * checkbox blocks without a hardcoded list in a Blade file.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * The admin panel is a session-guarded web app; the mobile API authorises
     * through policies on Sanctum tokens, so there is only ever one guard here.
     */
    private const GUARD = 'web';

    /**
     * group => permission names. The group is presentation, the name is the
     * contract that `can:` checks and Blade @can directives reference.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSION_GROUPS = [
        'users' => ['view_users', 'manage_users'],
        'quotes' => ['view_quotes', 'manage_quotes'],
        'bookings' => ['view_bookings', 'manage_bookings'],
        'carriers' => ['manage_carriers'],
        'reviews' => ['view_reviews', 'moderate_reviews'],
        'contact' => ['view_contact_messages', 'manage_contact_messages'],
        'content' => ['manage_content'],
        'reports' => ['view_reports'],
        'settings' => ['manage_settings'],
    ];

    public function run(): void
    {
        $all = $this->seedPermissions();

        foreach (UserRole::cases() as $case) {
            $role = Role::query()->updateOrCreate(
                ['name' => $case->value, 'guard_name' => self::GUARD],
                ['label' => $this->label($case)],
            );

            $grants = $this->grantsFor($case, $all);

            // Grants are admin-editable at runtime (see the UserRole docblock), so a
            // re-seed must not silently undo a deliberate revoke. The code-defined
            // baseline is therefore asserted only when the role is first created --
            // except for super-admin, where "has everything" is an invariant of the
            // role rather than a starting suggestion.
            if ($case === UserRole::SuperAdmin) {
                $role->syncPermissions($grants);
            } elseif ($role->wasRecentlyCreated) {
                $role->syncPermissions($grants);
            }
        }

        // Spatie caches the whole permission map; without this the freshly seeded
        // grants are invisible to any check made later in the same process.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedSuperAdminUser();
    }

    /**
     * @return list<string> every permission name, in group order
     */
    private function seedPermissions(): array
    {
        $names = [];

        foreach (self::PERMISSION_GROUPS as $group => $permissions) {
            foreach ($permissions as $name) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => self::GUARD],
                    ['group' => $group],
                );

                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $all
     * @return list<string>
     */
    private function grantsFor(UserRole $role, array $all): array
    {
        return match ($role) {
            UserRole::SuperAdmin => $all,

            // Everything operational, but not manage_settings: gateway keys and the
            // role matrix itself live behind that permission, and a role that can
            // rewrite the role matrix is a role that can promote itself.
            UserRole::Admin => array_values(array_diff($all, ['manage_settings'])),

            // The dispatch desk: price leads, book them, assign carriers.
            UserRole::Dispatcher => [
                'view_quotes', 'manage_quotes',
                'view_bookings', 'manage_bookings',
                'manage_carriers',
            ],

            // Inbox and moderation queue, plus read-only shipment lookup so an
            // agent can answer "where is my car" without being able to move it.
            UserRole::Support => [
                'view_contact_messages', 'manage_contact_messages',
                'view_reviews', 'moderate_reviews',
                'view_bookings',
            ],

            // No admin-panel permissions at all: a driver reaches their assigned
            // loads and a customer their own bookings through policies on the API,
            // which scope by ownership. A permission here would grant the whole
            // resource, which is precisely what must not happen.
            UserRole::Driver, UserRole::Customer => [],
        };
    }

    private function label(UserRole $role): string
    {
        return match ($role) {
            UserRole::SuperAdmin => 'Super Admin',
            UserRole::Admin => 'Administrator',
            UserRole::Dispatcher => 'Dispatcher',
            UserRole::Support => 'Support Agent',
            UserRole::Driver => 'Driver',
            UserRole::Customer => 'Customer',
        };
    }

    /**
     * §6.2: the first staff login. Credentials come from the environment because a
     * committed password is a published password -- this file ships in the repo.
     *
     * env() rather than config(): seeders run from the CLI, where .env is loaded.
     */
    private function seedSuperAdminUser(): void
    {
        $email = (string) (env('ADMIN_EMAIL') ?: 'admin@autotransport.test');

        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = (string) (env('ADMIN_NAME') ?: 'Site Administrator');
            $user->password = $this->resolveAdminPassword();   // 'hashed' cast handles it
        }

        // The password is never rewritten on a re-seed: an operator who rotated it
        // in the panel would otherwise find db:seed quietly reverting them to .env.
        $user->email_verified_at ??= now();
        $user->status = 'active';
        $user->save();

        $user->assignRole(UserRole::SuperAdmin->value);
    }

    private function resolveAdminPassword(): string
    {
        $password = (string) (env('ADMIN_PASSWORD') ?: '');

        if ($password !== '') {
            return $password;
        }

        // A shipped default credential on a public host is a breach waiting for a
        // scanner, so production gets a random one printed once to the console.
        if (app()->environment('production')) {
            $password = Str::password(24);

            $this->command?->warn(
                'ADMIN_PASSWORD was not set. Generated super-admin password (shown once): '.$password
            );

            return $password;
        }

        return 'password';
    }
}
