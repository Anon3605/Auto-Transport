<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * User administration. Two invariants are enforced here rather than in the view,
 * because a hidden form field is not a rule:
 *
 *   1. the last super-admin cannot be deleted or demoted -- an account nobody
 *      can escalate back into is a locked panel and a database console;
 *   2. nobody deletes their own account out from under their own session.
 *
 * The super-admin *grant* guard lives in the form requests, where it can report
 * itself in the error bag.
 */
class UserController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        return view('admin.users.index', [
            'users' => $this->query($request)->paginate(self::PER_PAGE)->withQueryString(),
            'roles' => Role::query()->orderBy('name')->get(),
            'statuses' => StoreUserRequest::STATUSES,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => $this->assignableRoles(),
            'statuses' => StoreUserRequest::STATUSES,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            // The password cast hashes on assignment, and the phone mutator
            // fills phone_normalized -- neither needs doing here.
            $user = User::query()->create($request->userAttributes());

            // An admin vouching for the address is the verification; there is no
            // confirmation round-trip on a staff-created account. Not fillable,
            // so mass assignment would have dropped it without a word.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles($request->roleNames());

            return $user;
        });

        // LogsActivity records the attribute changes; the roles are a separate
        // table, so the grant is logged by hand or it is not logged at all.
        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['roles' => $request->roleNames()])
            ->log('Account created in the admin panel');

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', "{$user->name} was created.");
    }

    public function show(User $user): View
    {
        $user->load(['roles', 'driverProfile']);

        return view('admin.users.show', [
            'user' => $user,

            // Capped rather than eager-loaded: a five-year customer has hundreds
            // of bookings and the page shows the last handful.
            'bookings' => $user->bookings()->with('service')->latest()->limit(10)->get(),
            'reviews' => $user->reviews()->with('service')->latest()->limit(10)->get(),
            'activities' => $this->recentActivity($user),
        ]);
    }

    public function edit(User $user): View
    {
        $user->load(['roles', 'driverProfile']);

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles(),
            'statuses' => StoreUserRequest::STATUSES,

            /*
             * Carriers are only needed for a driver, but loading them
             * unconditionally keeps the controller free of a role check that the
             * view already has to make anyway. One small query.
             *
             * Without this the approval loop is broken: a self-registered driver
             * has carrier_id = null, and AssignBookingRequest refuses to assign a
             * driver who does not work for the chosen carrier — so a driver with
             * no employer can never be given work, no matter what their status is.
             */
            'carriers' => Carrier::query()->orderBy('company_name')->get(['id', 'company_name']),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $roles = $request->roleNames();

        if ($this->wouldOrphanSuperAdmins($user, $roles)) {
            return back()
                ->withInput()
                ->with('error', 'This is the last super-admin. Promote someone else before changing this account.');
        }

        DB::transaction(function () use ($request, $user, $roles): void {
            $attributes = $request->userAttributes();

            // Only re-hash when a new password actually arrived: an empty field
            // means "leave it alone", and writing '' would lock the account out
            // in a way no error message would explain.
            if (($password = $request->validated('password')) !== null && $password !== '') {
                $attributes['password'] = $password;
            }

            $user->update($attributes);
            $user->syncRoles($roles);

            $this->syncDriverProfile($user, $roles, $request->validated('driver', []));
        });

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['roles' => $roles])
            ->log('Account updated in the admin panel');

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', "{$user->name} was updated.");
    }

    /**
     * Soft delete. bookings.user_id is restrictOnDelete, so a hard delete would
     * fail on any customer who ever shipped a car -- and the shipment record has
     * to survive the account anyway.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'You cannot delete the account you are signed in with.');
        }

        if ($this->wouldOrphanSuperAdmins($user, [])) {
            return back()->with('error', 'The last super-admin cannot be deleted.');
        }

        $user->delete();

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->log('Account deleted in the admin panel');

        return redirect()
            ->route('admin.users.index')
            ->with('status', "{$user->name} was deleted.");
    }

    /**
     * Search covers the three things a support agent has in front of them when a
     * customer calls: a name, an address, or a phone number. The phone match runs
     * against phone_normalized because nobody reads the punctuation back to you
     * the way they typed it (§4.12).
     *
     * @return Builder<User>
     */
    private function query(Request $request): Builder
    {
        $query = User::query()->with('roles')->latest('id');

        if (($term = trim((string) $request->query('q'))) !== '') {
            $phone = User::normalizePhone($term);

            $query->where(function (Builder $scoped) use ($term, $phone): void {
                $scoped->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");

                if ($phone !== null) {
                    $scoped->orWhere('phone_normalized', 'like', '%'.ltrim($phone, '+').'%');
                }
            });
        }

        if (in_array($status = (string) $request->query('status'), StoreUserRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (($role = (string) $request->query('role')) !== '') {
            // whereHas rather than Spatie's role() scope: role() throws
            // RoleDoesNotExist on a stale bookmark, and a 500 is a poor answer to
            // a query string.
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role));
        }

        return $query;
    }

    /**
     * A non-super-admin is not offered the role they may not grant. The form
     * request enforces it; this only keeps the checkbox honest.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(): Collection
    {
        $roles = Role::query()->orderBy('name')->get();

        if (auth()->user()?->hasRole(UserRole::SuperAdmin->value) === true) {
            return $roles;
        }

        return $roles->reject(fn (Role $role): bool => $role->name === UserRole::SuperAdmin->value)->values();
    }


    /**
     * Keep driver_profiles in step with the roles the account holds.
     *
     * Three cases, and the third is the one that matters:
     *
     *  - driver role, no profile  -> create one. A driver promoted by staff in the
     *    panel needs the row that self-registration would have made.
     *  - driver role, has profile -> update the licence and employer.
     *  - NOT a driver, has profile -> leave the row alone. Deleting it would throw
     *    away licence history and, because driver_profiles.user_id is UNIQUE,
     *    re-promoting the person later would silently lose what was recorded
     *    before. Roles come and go; the record of who they were does not.
     *
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $driver
     */
    private function syncDriverProfile(User $user, array $roles, array $driver): void
    {
        if (! in_array(UserRole::Driver->value, $roles, true)) {
            return;
        }

        $profile = $user->driverProfile()->firstOrNew([]);

        $profile->fill(array_filter([
            'license_number' => $driver['license_number'] ?? null,
            'license_state' => $driver['license_state'] ?? null,
            'license_expires_at' => $driver['license_expires_at'] ?? null,
            'cdl_class' => $driver['cdl_class'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));

        /*
         * carrier_id is set explicitly rather than through array_filter, because
         * an empty value here means "un-assign this driver from their carrier" —
         * a real action that array_filter would silently discard.
         */
        if (array_key_exists('carrier_id', $driver)) {
            $profile->carrier_id = ($driver['carrier_id'] === null || $driver['carrier_id'] === '')
                ? null
                : (int) $driver['carrier_id'];
        }

        if (array_key_exists('is_available', $driver)) {
            $profile->is_available = (bool) $driver['is_available'];
        }

        $user->driverProfile()->save($profile);
    }
    /**
     * True when applying $roles to $user would leave the system with no
     * super-admin. Deletion passes an empty role set, which is the same question.
     *
     * @param  list<string>  $roles
     */
    private function wouldOrphanSuperAdmins(User $user, array $roles): bool
    {
        $name = UserRole::SuperAdmin->value;

        if (! $user->hasRole($name) || in_array($name, $roles, true)) {
            return false;
        }

        return User::query()->whereHas('roles', fn (Builder $q) => $q->where('name', $name))->count() <= 1;
    }

    /**
     * Both directions of the audit trail: what was done TO this account, and
     * what this account did. A support agent looking at a compromised login
     * needs the second one as much as the first.
     *
     * @return Collection<int, Activity>
     */
    private function recentActivity(User $user): Collection
    {
        return Activity::query()
            ->with('causer')
            ->where(function (Builder $query) use ($user): void {
                $query->where(fn (Builder $q) => $q
                    ->where('subject_type', $user->getMorphClass())
                    ->where('subject_id', $user->getKey()))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('causer_type', $user->getMorphClass())
                        ->where('causer_id', $user->getKey()));
            })
            ->latest('id')
            ->limit(25)
            ->get();
    }
}
