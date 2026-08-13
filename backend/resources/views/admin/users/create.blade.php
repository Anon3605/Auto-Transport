@extends('layouts.admin')

@section('page_title', 'New user')

@section('breadcrumb')
    <li><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li aria-current="page">New</li>
@endsection

@section('content')
    @php
        /*
         * $roles may arrive as Role models or as bare names, and $statuses as a
         * list or a value => label map. Normalising here keeps the markup below
         * from branching on either shape.
         */
        $roleOptions = collect($roles)->map(fn ($role): array => is_object($role)
            ? ['name' => $role->name, 'label' => $role->label ?: Str::headline($role->name)]
            : ['name' => (string) $role, 'label' => Str::headline((string) $role)]);

        $statusOptions = collect($statuses)->mapWithKeys(fn ($label, $key): array => is_int($key)
            ? [(string) $label => Str::headline((string) $label)]
            : [(string) $key => (string) $label]);

        $checkedRoles = (array) old('roles', []);
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                The account is usable immediately &mdash; there is no invitation email in this flow.
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">Cancel</a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.users.store') }}" class="stack">
        @csrf

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Identity</h2>
            </div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="form-field @error('name') has-error @enderror">
                        <label for="name">Full name <span class="form-required" aria-hidden="true">*</span></label>
                        <input class="input" type="text" name="name" id="name" value="{{ old('name') }}"
                               required autocomplete="name" autofocus
                               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')
                            <p class="form-error" id="name-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('email') has-error @enderror">
                        <label for="email">Email <span class="form-required" aria-hidden="true">*</span></label>
                        <input class="input" type="email" name="email" id="email" value="{{ old('email') }}"
                               required autocomplete="email"
                               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        <p class="form-hint">Used to sign in. Must be unique.</p>
                        @error('email')
                            <p class="form-error" id="email-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('phone') has-error @enderror">
                        <label for="phone">Phone</label>
                        <input class="input" type="tel" name="phone" id="phone" value="{{ old('phone') }}"
                               autocomplete="tel" inputmode="tel"
                               @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                        <p class="form-hint">Any format &mdash; it is normalised on save.</p>
                        @error('phone')
                            <p class="form-error" id="phone-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('status') has-error @enderror">
                        <label for="status">Status <span class="form-required" aria-hidden="true">*</span></label>
                        <select class="select" name="status" id="status" required
                                @error('status') aria-invalid="true" aria-describedby="status-error" @enderror>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status')
                            <p class="form-error" id="status-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('locale') has-error @enderror">
                        <label for="locale">Locale</label>
                        <input class="input" type="text" name="locale" id="locale" value="{{ old('locale', 'en') }}"
                               maxlength="8"
                               @error('locale') aria-invalid="true" aria-describedby="locale-error" @enderror>
                        @error('locale')
                            <p class="form-error" id="locale-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('timezone') has-error @enderror">
                        <label for="timezone">Timezone</label>
                        <input class="input" type="text" name="timezone" id="timezone"
                               value="{{ old('timezone', 'UTC') }}" maxlength="64"
                               @error('timezone') aria-invalid="true" aria-describedby="timezone-error" @enderror>
                        <p class="form-hint">IANA name, e.g. America/Chicago.</p>
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
                        <label for="password">Password <span class="form-required" aria-hidden="true">*</span></label>
                        <input class="input" type="password" name="password" id="password" required
                               autocomplete="new-password"
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        <p class="form-hint">Share it over a channel that is not this panel.</p>
                        @error('password')
                            <p class="form-error" id="password-error"><span aria-hidden="true">&#9888;</span><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    <div class="form-field @error('password_confirmation') has-error @enderror">
                        <label for="password_confirmation">Confirm password <span class="form-required" aria-hidden="true">*</span></label>
                        <input class="input" type="password" name="password_confirmation" id="password_confirmation"
                               required autocomplete="new-password"
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
                    <button type="submit" class="btn btn--primary">Create user</button>
                    <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">Cancel</a>
                </div>
            </div>
        </section>
    </form>
@endsection
