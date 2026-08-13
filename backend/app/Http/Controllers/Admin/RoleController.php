<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The grant matrix. UserRole's docblock is explicit that role NAMES are code and
 * permission GRANTS are data -- this screen is why: retuning what a dispatcher
 * may do is a checkbox, not a deploy.
 *
 * Roles themselves are not creatable or deletable here. Every isStaff() check in
 * the codebase resolves a name through UserRole::tryFrom(), so a role invented at
 * runtime would carry permissions while failing the staff gate.
 */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),

            // permissions.group exists to lay this screen out; ungrouped rows
            // would otherwise vanish from a grouped render.
            'permissionGroups' => Permission::query()
                ->orderBy('group')
                ->orderBy('name')
                ->get()
                ->groupBy(fn (Permission $permission): string => $permission->group ?: 'other'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $superAdmin = UserRole::SuperAdmin->value;
        $actor = $request->user();

        // Editing the top role is how you would quietly remove the only account
        // that can undo your change.
        if ($role->name === $superAdmin && ! $actor->hasRole($superAdmin)) {
            return back()->with('error', 'Only a super-admin can edit the super-admin role.');
        }

        $names = array_values(array_unique($validated['permissions'] ?? []));

        DB::transaction(function () use ($role, $names): void {
            $role->syncPermissions($names);
        });

        // Grants are cached by the package; a stale cache means the checkbox you
        // just unticked keeps working until the TTL expires.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity()
            ->performedOn($role)
            ->causedBy($actor)
            ->withProperties(['permissions' => $names])
            ->log("Permissions updated for role {$role->name}");

        return redirect()
            ->route('admin.roles.index')
            ->with('status', "Permissions for {$role->name} were saved.");
    }
}
