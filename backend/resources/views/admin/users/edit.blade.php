@extends('layouts.admin')

@section('page_title', 'Edit ' . $user->name)

@section('breadcrumb')
    <li><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li><a href="{{ route('admin.users.show', $user) }}">{{ Str::limit($user->name, 24) }}</a></li>
    <li aria-current="page">Edit</li>
@endsection

@section('content')
    @php
        $roleOptions = collect($roles)->map(fn ($role): array => is_object($role)
            ? ['name' => $role->name, 'label' => $role->label ?: Str::headline($role->name)]
            : ['name' => (string) $role, 'label' => Str::headline((string) $role)]);

        $statusOptions = collect($statuses)->mapWithKeys(fn ($label, $key): array => is_int($key)
            ? [(string) $label => Str::headline((string) $label)]
            : [(string) $key => (string) $label]);

        // Old input wins on a failed validation pass; otherwise the stored roles.
        $checkedRoles = (array) old('roles', $user->getRoleNames()->all());
        $isSelf = auth()->id() === $user->id;
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                <span class="ref">{{ $user->ulid }}</span>
                joined {{ $user->created_at?->format('j M Y') }}
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.show', $user) }}">View profile</a>
        </div>
    </div>

    <div class="stack">
    @if ($isSelf)
        <div class="alert alert--info">
            <span class="alert__icon" aria-hidden="true">&#9432;</span>
            <div class="alert__body">
                This is your own account. Removing your own roles can lock you out of the panel.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="stack">
        @csrf
        @method('PUT')

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Identity</h2>
            </div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="form-field @error('name') has-error @enderror">
                        <label for="name">Full name <span class="form-required" aria-hidden="true">*</span></label>
                        <input class="input" type="text" name="name" id="name"
                               value="{{ old('name', $user->name) }}" required autocomplete="name"
                               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')
                            <p class="form-error" id="name-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('email') has-error @enderror">
                        <label for="email">Email <span class="form-required" aria-hidden="true">*</span></label>
                        <input class="input" type="email" name="email" id="email"
                               value="{{ old('email', $user->email) }}" required autocomplete="email"
                               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @if ($user->email_verified_at === null)
                            <p class="form-hint">This address has never been verified.</p>
                        @endif
                        @error('email')
                            <p class="form-error" id="email-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('phone') has-error @enderror">
                        <label for="phone">Phone</label>
                        <input class="input" type="tel" name="phone" id="phone"
                               value="{{ old('phone', $user->phone) }}" autocomplete="tel" inputmode="tel"
                               @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                        @if ($user->phone_normalized)
                            <p class="form-hint">Normalised as <span class="mono">{{ $user->phone_normalized }}</span>.</p>
                        @endif
                        @error('phone')
                            <p class="form-error" id="phone-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('status') has-error @enderror">
                        <label for="status">Status <span class="form-required" aria-hidden="true">*</span></label>
                        <select class="select" name="status" id="status" required
                                @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $user->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if ($user->isLocked())
                            <p class="form-hint">
                                Locked by failed sign-ins until {{ $user->locked_until->format('j M Y H:i') }}.
                            </p>
                        @endif
                        @error('status')
                            <p class="form-error" id="status-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('locale') has-error @enderror">
                        <label for="locale">Locale</label>
                        <input class="input" type="text" name="locale" id="locale"
                               value="{{ old('locale', $user->locale) }}" maxlength="8"
                               @error('locale') aria-invalid="true" aria-describedby="locale-error" @enderror>
                        @error('locale')
                            <p class="form-error" id="locale-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('timezone') has-error @enderror">
                        <label for="timezone">Timezone</label>
                        <input class="input" type="text" name="timezone" id="timezone"
                               value="{{ old('timezone', $user->timezone) }}" maxlength="64"
                               @error('timezone') aria-invalid="true" aria-describedby="timezone-error" @enderror>
                        @error('timezone')
                            <p class="form-error" id="timezone-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Password</h2>
            </div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="form-field @error('password') has-error @enderror">
                        <label for="password">New password</label>
                        <input class="input" type="password" name="password" id="password"
                               autocomplete="new-password"
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        <p class="form-hint">Leave blank to keep the current password.</p>
                        @error('password')
                            <p class="form-error" id="password-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('password_confirmation') has-error @enderror">
                        <label for="password_confirmation">Confirm new password</label>
                        <input class="input" type="password" name="password_confirmation" id="password_confirmation"
                               autocomplete="new-password"
                               @error('password_confirmation') aria-invalid="true" aria-describedby="password-confirmation-error" @enderror>
                        @error('password_confirmation')
                            <p class="form-error" id="password-confirmation-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Roles</h2>
                <div class="card__actions">
                    <a class="btn btn--ghost btn--sm" href="{{ route('admin.roles.index') }}">What can each role do?</a>
                </div>
            </div>
            <div class="card__body">
                <fieldset>
                    <legend class="sr-only">Assign roles</legend>
                    <div class="check-grid">
                        @foreach ($roleOptions as $index => $role)
                            <label class="check" for="role-{{ $index }}">
                                <input type="checkbox" name="roles[]" id="role-{{ $index }}"
                                       value="{{ $role['name'] }}"
                                       @checked(in_array($role['name'], $checkedRoles, true))>
                                <span class="check__text">
                                    {{ $role['label'] }}
                                    <span class="check__hint mono">{{ $role['name'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('roles')
                        <p class="form-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                    @enderror
                </fieldset>
            </div>
            <div class="card__foot">
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Save changes</button>
                    <a class="btn btn--ghost" href="{{ route('admin.users.show', $user) }}">Cancel</a>
                </div>
            </div>
        </section>
    </form>

    @if (! $isSelf)
        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Danger zone</h2>
            </div>
            <div class="card__body">
                <div class="row">
                    <div class="stack-sm">
                        <p class="strong small">Delete this account</p>
                        <p class="small muted">
                            Soft delete: bookings, quotes and reviews stay on file, and the row can be
                            restored from the database.
                        </p>
                    </div>
                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="push"
                          data-confirm="Delete {{ $user->name }}? Their bookings and reviews stay on file.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn--danger">Delete user</button>
                    </form>
                </div>
            </div>
        </section>
    @endif
    </div>
@endsection
