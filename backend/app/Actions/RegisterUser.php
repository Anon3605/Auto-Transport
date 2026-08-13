<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Self-service registration from the mobile client. Lives outside the
 * controller because the admin panel and the seeders create customers too, and
 * an account that exists without its role is an account that can do nothing.
 */
class RegisterUser
{
    /**
     * Guest quote requests are deliberately NOT claimed here.
     *
     * docs/database-design.md §4.10: claiming on registration lets anyone type a
     * stranger's address and inherit their quote history -- addresses and VINs
     * included. The claim belongs on Illuminate\Auth\Events\Verified, which only
     * fires once the human has proved they read mail at that address:
     *
     *     // app/Listeners/ClaimGuestQuoteRequests.php (not owned by this file)
     *     public function handle(Verified $event): void
     *     {
     *         QuoteRequest::claimGuestRequestsFor($event->user);
     *     }
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $attributes
     */
    public function __invoke(array $attributes): User
    {
        $user = DB::transaction(function () use ($attributes): User {
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                // 'hashed' cast on the model; the plaintext never reaches the insert.
                'password' => $attributes['password'],
                // The model's set mutator writes phone_normalized alongside this.
                'phone' => $attributes['phone'] ?? null,
                'status' => 'active',
            ]);

            // findOrCreate, not findByName: registration must not 500 because a
            // seeder has not run yet on a fresh environment. The row is a bare
            // name -- the seeder attaches permissions to it by name later.
            $user->assignRole(Role::findOrCreate(UserRole::Customer->value, 'web'));

            return $user;
        });

        // Outside the transaction: the verification mail must not be sent for a
        // row that a later statement rolls back.
        event(new Registered($user));

        return $user;
    }
}
