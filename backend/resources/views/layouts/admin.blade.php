{{--
    Admin shell. The stylesheet is served straight from /public -- no Vite, no
    manifest, so the panel keeps rendering when nobody has run a build.

    Section contract for child views:
      page_title  plain text, used in <title> and the topbar <h1>
      breadcrumb  <li> elements appended after the "Admin" root crumb
      content     the canvas
--}}
@php
    // Cheap cache-buster: one stat call beats telling operators to hard-refresh.
    $cssPath = public_path('css/admin.css');
    $jsPath = public_path('js/admin.js');
    $cssVersion = is_file($cssPath) ? filemtime($cssPath) : null;
    $jsVersion = is_file($jsPath) ? filemtime($jsPath) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- An authenticated panel has no business in an index. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('page_title', 'Dashboard') &middot; AutoTransport Admin</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}{{ $cssVersion ? '?v=' . $cssVersion : '' }}">
    <script>
        /* Runs before first paint: a dark-theme operator must never eat a white
           flash on every navigation. admin.js takes over the toggle from here. */
        try {
            var storedTheme = window.localStorage.getItem('autotransport.admin.theme');
            if (storedTheme === 'dark' || storedTheme === 'light') {
                document.documentElement.setAttribute('data-theme', storedTheme);
            }
        } catch (e) {}
    </script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to main content</a>

    {{-- Off-canvas nav state lives in this checkbox so the sidebar opens with
         JavaScript disabled. It must stay a sibling *before* .shell -- the CSS
         reaches the sidebar with `.nav-toggle:checked ~ .shell`. --}}
    <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Show navigation menu">

    <div class="shell">
        <label for="nav-toggle" class="nav-scrim" aria-hidden="true"></label>

        @include('admin.partials.sidebar')

        <div class="main-col">
            @include('admin.partials.topbar')

            <main class="canvas" id="main">
                @include('admin.partials.flash')

                @yield('content')
            </main>
        </div>
    </div>

    <script src="{{ asset('js/admin.js') }}{{ $jsVersion ? '?v=' . $jsVersion : '' }}" defer></script>
</body>
</html>
