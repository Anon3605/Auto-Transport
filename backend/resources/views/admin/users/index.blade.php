@extends('layouts.admin')

@section('page_title', 'Users')

@section('breadcrumb')
    <li aria-current="page">Users</li>
@endsection

@section('content')
    @php
        // No enum backs users.status; these are the values the migration documents.
        $statusOptions = ['active', 'suspended', 'pending'];
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">Staff, drivers and customer accounts.</p>
        </div>
        @can('manage_users')
            <div class="page-head__actions">
                <a class="btn btn--secondary" href="{{ route('admin.roles.index') }}">Roles &amp; permissions</a>
                <a class="btn btn--primary" href="{{ route('admin.users.create') }}">New user</a>
            </div>
        @endcan
    </div>

    <section class="card card--flush">
        <form method="GET" action="{{ route('admin.users.index') }}" class="filter-bar">
            <div class="form-field form-field--wide">
                <label for="filter-q">Search</label>
                <input class="input" type="search" name="q" id="filter-q" value="{{ request('q') }}"
                       placeholder="Name, email or phone">
            </div>

            <div class="form-field">
                <label for="filter-role">Role</label>
                <select class="select" name="role" id="filter-role">
                    <option value="">Any role</option>
                    @foreach (\App\Enums\UserRole::cases() as $role)
                        <option value="{{ $role->value }}" @selected(request('role') === $role->value)>
                            {{ Str::headline($role->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <label for="filter-status">Status</label>
                <select class="select" name="status" id="filter-status">
                    <option value="">Any status</option>
                    @foreach ($statusOptions as $statusOption)
                        <option value="{{ $statusOption }}" @selected(request('status') === $statusOption)>
                            {{ Str::headline($statusOption) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="filter-bar__actions">
                <button type="submit" class="btn btn--secondary">Apply</button>
                @if (request()->hasAny(['q', 'role', 'status']))
                    <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">Clear</a>
                @endif
            </div>
        </form>

        @if (count($users) === 0)
            @include('admin.partials.empty-state', [
                'icon' => '◍',
                'title' => 'No users match',
                'text' => 'Loosen the filters, or create the account you were looking for.',
                'actionUrl' => request()->hasAny(['q', 'role', 'status']) ? route('admin.users.index') : null,
                'actionLabel' => 'Clear filters',
            ])
        @else
            <div class="table-wrap">
                <table class="table">
                    <caption class="sr-only">User accounts</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Phone</th>
                            <th scope="col">Roles</th>
                            <th scope="col">Status</th>
                            <th scope="col">Last sign-in</th>
                            <th scope="col" class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            @php
                                $initials = collect(preg_split('/\s+/', trim((string) $user->name)))
                                    ->filter()
                                    ->take(2)
                                    ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
                                    ->implode('') ?: mb_strtoupper(mb_substr((string) $user->email, 0, 1));
                                $roleNames = $user->getRoleNames();
                            @endphp
                            <tr>
                                <th scope="row">
                                    <span class="identity">
                                        <span class="avatar" aria-hidden="true">
                                            @if ($user->avatar_url)
                                                <img src="{{ $user->avatar_url }}" alt="">
                                            @else
                                                {{ $initials }}
                                            @endif
                                        </span>
                                        <span class="identity__text">
                                            <a class="table__primary" href="{{ route('admin.users.show', $user) }}">
                                                {{ $user->name }}
                                            </a>
                                            <span class="identity__meta">{{ $user->email }}</span>
                                        </span>
                                    </span>
                                </th>
                                <td class="num">{{ $user->phone ?: '—' }}</td>
                                <td>
                                    @if ($roleNames->isEmpty())
                                        <span class="muted">—</span>
                                    @else
                                        <span class="row-tight">
                                            @foreach ($roleNames as $roleName)
                                                <span class="chip">{{ Str::headline($roleName) }}</span>
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="row-tight">
                                        @include('admin.partials.status-badge', ['status' => $user->status])
                                        @if ($user->isLocked())
                                            <span class="badge badge--danger" title="Locked until {{ $user->locked_until }}">Locked</span>
                                        @endif
                                        @if ($user->email_verified_at === null)
                                            <span class="badge badge--warning">Unverified</span>
                                        @endif
                                    </span>
                                </td>
                                <td>
                                    @if ($user->last_login_at)
                                        <span title="{{ $user->last_login_at }}">{{ $user->last_login_at->diffForHumans() }}</span>
                                        <span class="table__sub mono">{{ $user->last_login_ip ?: '' }}</span>
                                    @else
                                        <span class="muted">Never</span>
                                    @endif
                                </td>
                                <td class="actions">
                                    <span class="btn-group">
                                        <a class="btn btn--ghost btn--sm" href="{{ route('admin.users.show', $user) }}">View</a>
                                        @can('manage_users')
                                            <a class="btn btn--secondary btn--sm" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                                            @if (auth()->id() !== $user->id)
                                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                                      class="inline-form"
                                                      data-confirm="Delete {{ $user->name }}? Their bookings stay on file.">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn--outline-danger btn--sm">Delete</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('admin.partials.pagination', ['paginator' => $users])
        @endif
    </section>
@endsection
