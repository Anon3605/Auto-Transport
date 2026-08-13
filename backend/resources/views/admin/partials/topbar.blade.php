{{--
    Sticky header: breadcrumb + page title on the left, theme toggle and user
    menu on the right. Reads @yield('page_title') / @yield('breadcrumb') from
    the child view -- sections are registered before the layout renders, so
    yielding them inside an include is safe.
--}}
@php
    $me = auth()->user();

    // Two initials, upper-cased; the email's first letter covers a blank name.
    $initials = $me
        ? (collect(preg_split('/\s+/', trim((string) $me->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('') ?: mb_strtoupper(mb_substr((string) $me->email, 0, 1)))
        : '';
@endphp

<header class="topbar">
    {{-- Label, not a button: the checkbox in the layout holds the state so the
         off-canvas nav survives JavaScript being off. --}}
    <label for="nav-toggle" class="hamburger" aria-hidden="true">
        <span aria-hidden="true">&#9776;</span>
    </label>

    <div class="topbar__head">
        <ol class="breadcrumb">
            <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
            @yield('breadcrumb')
        </ol>
        <h1 class="topbar__title">@yield('page_title', 'Dashboard')</h1>
    </div>

    <div class="topbar__actions">
        {{-- Shipped hidden; admin.js unhides it and keeps the label in sync. --}}
        <button type="button" class="icon-btn" data-theme-toggle hidden
                aria-label="Switch colour theme" title="Switch colour theme">
            <span data-theme-icon aria-hidden="true">&#9790;</span>
        </button>

        @if ($me)
            <details class="usermenu">
                <summary aria-label="Account menu for {{ $me->name }}">
                    <span class="avatar avatar--sm avatar--brand" aria-hidden="true">
                        @if ($me->avatar_url)
                            <img src="{{ $me->avatar_url }}" alt="">
                        @else
                            {{ $initials }}
                        @endif
                    </span>
                    <span class="small strong nowrap">{{ Str::limit($me->name, 18) }}</span>
                    <span class="usermenu__caret" aria-hidden="true">&#9662;</span>
                </summary>

                <div class="usermenu__panel">
                    <div class="usermenu__head">
                        <div class="stack-sm">
                            <div>
                                <div class="small strong">{{ $me->name }}</div>
                                <div class="tiny muted">{{ $me->email }}</div>
                            </div>
                            @if ($me->getRoleNames()->isNotEmpty())
                                <div class="row-tight">
                                    @foreach ($me->getRoleNames() as $roleName)
                                        <span class="chip">{{ Str::headline($roleName) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    @can('view_users')
                        <a class="usermenu__item" href="{{ route('admin.users.show', $me) }}">
                            <span aria-hidden="true">&#9677;</span> My profile
                        </a>
                    @endcan
                    @can('manage_settings')
                        <a class="usermenu__item" href="{{ route('admin.settings.index') }}">
                            <span aria-hidden="true">&#9881;</span> Settings
                        </a>
                    @endcan

                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="usermenu__item usermenu__item--danger">
                            <span aria-hidden="true">&#8594;</span> Sign out
                        </button>
                    </form>
                </div>
            </details>
        @endif
    </div>
</header>
