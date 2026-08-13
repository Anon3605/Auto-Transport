{{--
    Standalone page: the shell (sidebar, user menu) has nothing to show a guest,
    so this does not extend layouts.admin. It still honours the stored theme.

    AdminLoginController puts every failure on the `email` key on purpose -- one
    message for "no such user" and "wrong password" keeps the form from being an
    account-enumeration oracle. Rendering it under the email field is therefore
    the whole error surface.
--}}
@php
    $cssPath = public_path('css/admin.css');
    $cssVersion = is_file($cssPath) ? filemtime($cssPath) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Sign in &middot; AutoTransport Admin</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}{{ $cssVersion ? '?v=' . $cssVersion : '' }}">
    <script>
        try {
            var storedTheme = window.localStorage.getItem('autotransport.admin.theme');
            if (storedTheme === 'dark' || storedTheme === 'light') {
                document.documentElement.setAttribute('data-theme', storedTheme);
            }
        } catch (e) {}
    </script>
</head>
<body>
    <main class="auth-page">
        <div class="auth-card">
            <div class="auth-card__brand">
                <span class="brand__mark" aria-hidden="true">AT</span>
                <div>
                    <h1 class="auth-card__title">AutoTransport</h1>
                    <p class="auth-card__sub">Operations panel</p>
                </div>
            </div>

            {{-- Errors are rendered inline under the field, so the summary would
                 only repeat them. --}}
            @include('admin.partials.flash', ['showErrors' => false])

            <form method="POST" action="{{ route('admin.login.attempt') }}" class="stack">
                @csrf

                <div class="form-field @error('email') has-error @enderror">
                    <label for="email">Email address</label>
                    <input class="input" type="email" name="email" id="email"
                           value="{{ old('email') }}"
                           autocomplete="username" inputmode="email"
                           required autofocus
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <p class="form-error" id="email-error">
                            <span aria-hidden="true">&#9888;</span>
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <div class="form-field @error('password') has-error @enderror">
                    <label for="password">Password</label>
                    <input class="input" type="password" name="password" id="password"
                           autocomplete="current-password" required
                           @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')
                        <p class="form-error" id="password-error">
                            <span aria-hidden="true">&#9888;</span>
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <label class="check" for="remember">
                    <input type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
                    <span class="check__text">
                        Keep me signed in
                        <span class="check__hint">Only on a device you control.</span>
                    </span>
                </label>

                <button type="submit" class="btn btn--primary btn--block">Sign in</button>
            </form>

            <p class="auth-foot">
                <span>Staff access only.</span>
                <span class="push">Five failed attempts lock the account for 15 minutes.</span>
            </p>
        </div>
    </main>
</body>
</html>
