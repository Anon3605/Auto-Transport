@extends('layouts.admin')

@section('page_title', 'Roles & permissions')

@section('breadcrumb')
    <li><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li aria-current="page">Roles</li>
@endsection

@section('content')
    @php
        /*
         * Accepts Permission models or bare names, and tolerates a null group
         * (the column is nullable) by filing those under "Other".
         */
        $groups = collect($permissionGroups)
            ->mapWithKeys(fn ($permissions, $group): array => [
                (string) ($group ?: 'Other') => collect($permissions)->map(fn ($permission): array => is_object($permission)
                    ? ['name' => $permission->name, 'label' => Str::headline($permission->name)]
                    : ['name' => (string) $permission, 'label' => Str::headline((string) $permission)]),
            ]);
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                Grants live in the database, so changing what a role can do is a save here, not a deploy.
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">Back to users</a>
        </div>
    </div>

    <div class="stack">
        @foreach ($roles as $role)
            @php
                $roleName = is_object($role) ? $role->name : (string) $role;
                $roleLabel = is_object($role) ? ($role->label ?: Str::headline($roleName)) : Str::headline($roleName);
                $granted = is_object($role) ? $role->permissions->pluck('name')->all() : [];
                $memberCount = is_object($role) ? $role->users()->count() : 0;
                $roleKey = Str::slug($roleName);
                $enumCase = \App\Enums\UserRole::tryFrom($roleName);
            @endphp

            {{-- One form per role: a single mega-form would make an accidental
                 save on role A silently rewrite role B. --}}
            <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="card"
                  data-confirm="Update what every {{ $roleLabel }} can do? This applies immediately.">
                @csrf
                @method('PUT')

                <div class="card__head">
                    <h2 class="card__title">{{ $roleLabel }}</h2>
                    <div class="card__actions">
                        <span class="ref">{{ $roleName }}</span>
                        @if ($enumCase?->isStaff())
                            <span class="badge badge--info">Panel access</span>
                        @endif
                        <span class="chip">
                            {{ number_format($memberCount) }} {{ Str::plural('member', $memberCount) }}
                        </span>
                        <span class="chip">
                            {{ number_format(count($granted)) }} granted
                        </span>
                    </div>
                </div>

                <div class="card__body">
                    @if ($groups->isEmpty())
                        <p class="small muted">No permissions have been defined yet.</p>
                    @else
                        <div class="stack">
                            @foreach ($groups as $groupName => $permissions)
                                <fieldset class="fieldset">
                                    <legend>{{ Str::headline($groupName) }}</legend>
                                    <div class="check-grid">
                                        @foreach ($permissions as $permission)
                                            @php
                                                $inputId = 'perm-' . $roleKey . '-' . Str::slug($permission['name']);
                                            @endphp
                                            <label class="check" for="{{ $inputId }}">
                                                {{-- Stored state, not old(): several forms share this
                                                     page and old input from one would re-check the rest. --}}
                                                <input type="checkbox" name="permissions[]" id="{{ $inputId }}"
                                                       value="{{ $permission['name'] }}"
                                                       @checked(in_array($permission['name'], $granted, true))>
                                                <span class="check__text">
                                                    {{ $permission['label'] }}
                                                    <span class="check__hint mono">{{ $permission['name'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="card__foot">
                    <button type="submit" class="btn btn--primary btn--sm">Save {{ $roleLabel }}</button>
                    <p class="tiny faint push">
                        Applies immediately to {{ number_format($memberCount) }}
                        {{ Str::plural('account', $memberCount) }}.
                    </p>
                </div>
            </form>
        @endforeach
    </div>
@endsection
