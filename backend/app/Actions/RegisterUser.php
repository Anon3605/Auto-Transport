<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Creates a self-registered account.
 *
 * Two account types, and they are NOT equivalent:
 *
 *   customer — active immediately. Nothing they can do carries risk to anyone
 *              else; the worst case is an abandoned account.
 *
 *   driver   — created PENDING. Anyone can type "driver" into a form, and a
 *              driver is somebody a customer hands a vehicle to. The licence
 *              details they supply are claims, not verified facts, so the
 *              account exists but is inert until a human checks the licence and
 *              links them to a carrier.
 *
 * The pending state is enforced in two independent places, which is deliberate:
 *
 *   1. `users.status = 'pending'` — BookingPolicy::create() requires 'active',
 *      so a pending account cannot transact.
 *   2. `driver_profiles.carrier_id = null` — AssignBookingRequest refuses to
 *      assign a driver who does not work for the chosen carrier, so an unvetted
 *      driver is unassignable *by construction* rather than by a status check
 *      somebody might forget.
 *
 * Either alone would be a single point of failure. Together, forgetting one
 * still leaves the other holding.
 */
class RegisterUser
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $driverProfile  licence claims, when registering a driver
     */
    public function __invoke(array $attributes, ?array $driverProfile = null): User
    {
        $isDriver = $driverProfile !== null;

        $user = DB::transaction(function () use ($attributes, $driverProfile, $isDriver): User {
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                // 'hashed' cast on the model; the plaintext never reaches the insert.
                'password' => $attributes['password'],
                // The model's set mutator writes phone_normalized alongside this.
                'phone' => $attributes['phone'] ?? null,

                // A driver waits for a human. A customer does not.
                'status' => $isDriver ? 'pending' : 'active',
            ]);

            // findOrCreate, not findByName: registration must not 500 because a
            // seeder has not run yet on a fresh environment. The row is a bare
            // name -- the seeder attaches permissions to it by name later.
            $role = $isDriver ? UserRole::Driver : UserRole::Customer;
            $user->assignRole(Role::findOrCreate($role->value, 'web'));

            if ($isDriver) {
                DriverProfile::create([
                    'user_id' => $user->id,

                    // No employer yet. This is the load-bearing half of the
                    // vetting gate -- see the class docblock.
                    'carrier_id' => null,

                    'license_number' => $driverProfile['license_number'] ?? null,
                    'license_state' => $driverProfile['license_state'] ?? null,
                    'license_expires_at' => $driverProfile['license_expires_at'] ?? null,
                    'cdl_class' => $driverProfile['cdl_class'] ?? null,

                    // Not available for dispatch until approved. The column
                    // defaults to true, which is right for an existing employee
                    // and wrong for an applicant.
                    'is_available' => false,
                ]);
            }

            return $user;
        });

        // Outside the transaction: the verification mail must not be sent for a
        // row that a later statement rolls back.
        event(new Registered($user));

        return $user;
    }
}
