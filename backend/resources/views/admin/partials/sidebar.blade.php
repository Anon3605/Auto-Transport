{{--
    Fixed navigation. Items are gated on the same permission names the routes
    enforce (see routes/web.php) so a support agent is never shown a link that
    would answer 403 -- the gate is the route's, this only avoids dead ends.

    Grouping mirrors how the day is worked, not how the tables are laid out:
    Overview / Operations / Reputation / Content / System.
--}}
@php
    $active = fn (string ...$patterns): bool => request()->routeIs(...$patterns);
@endphp

<aside class="sidebar" aria-label="Admin sections">
    <a class="brand" href="{{ route('admin.dashboard') }}">
        <span class="brand__mark" aria-hidden="true">AT</span>
        <span>
            <span class="brand__name">AutoTransport</span>
            <span class="brand__sub">Operations</span>
        </span>
    </a>

    <nav class="nav">
        <div class="nav__group">
            <p class="nav__title" id="nav-grp-overview">Overview</p>
            <ul class="nav__list" aria-labelledby="nav-grp-overview">
                <li>
                    <a class="nav__link" href="{{ route('admin.dashboard') }}"
                       @if ($active('admin.dashboard')) aria-current="page" @endif>
                        <span class="nav__icon" aria-hidden="true">&#9638;</span>
                        Dashboard
                    </a>
                </li>
            </ul>
        </div>

        @canany(['view_bookings', 'view_quotes', 'view_contact_messages'])
            <div class="nav__group">
                <p class="nav__title" id="nav-grp-ops">Operations</p>
                <ul class="nav__list" aria-labelledby="nav-grp-ops">
                    @can('view_bookings')
                        <li>
                            <a class="nav__link" href="{{ route('admin.bookings.index') }}"
                               @if ($active('admin.bookings.*')) aria-current="page" @endif>
                                <span class="nav__icon" aria-hidden="true">&#9636;</span>
                                Bookings
                            </a>
                        </li>
                    @endcan
                    @can('view_quotes')
                        <li>
                            <a class="nav__link" href="{{ route('admin.quotes.index') }}"
                               @if ($active('admin.quotes.*')) aria-current="page" @endif>
                                <span class="nav__icon" aria-hidden="true">&#9672;</span>
                                Quote requests
                            </a>
                        </li>
                    @endcan
                    @can('view_contact_messages')
                        <li>
                            <a class="nav__link" href="{{ route('admin.messages.index') }}"
                               @if ($active('admin.messages.*')) aria-current="page" @endif>
                                <span class="nav__icon" aria-hidden="true">&#9993;</span>
                                Messages
                            </a>
                        </li>
                    @endcan
                </ul>
            </div>
        @endcanany

        @can('view_reviews')
            <div class="nav__group">
                <p class="nav__title" id="nav-grp-rep">Reputation</p>
                <ul class="nav__list" aria-labelledby="nav-grp-rep">
                    <li>
                        <a class="nav__link" href="{{ route('admin.reviews.index') }}"
                           @if ($active('admin.reviews.*')) aria-current="page" @endif>
                            <span class="nav__icon" aria-hidden="true">&#9733;</span>
                            Reviews
                        </a>
                    </li>
                </ul>
            </div>
        @endcan

        @can('manage_content')
            <div class="nav__group">
                <p class="nav__title" id="nav-grp-content">Content</p>
                <ul class="nav__list" aria-labelledby="nav-grp-content">
                    <li>
                        <a class="nav__link" href="{{ route('admin.services.index') }}"
                           @if ($active('admin.services.*')) aria-current="page" @endif>
                            <span class="nav__icon" aria-hidden="true">&#9670;</span>
                            Services
                        </a>
                    </li>
                </ul>
            </div>
        @endcan

        @canany(['view_users', 'manage_users', 'manage_settings'])
            <div class="nav__group">
                <p class="nav__title" id="nav-grp-system">System</p>
                <ul class="nav__list" aria-labelledby="nav-grp-system">
                    @can('view_users')
                        <li>
                            <a class="nav__link" href="{{ route('admin.users.index') }}"
                               @if ($active('admin.users.*')) aria-current="page" @endif>
                                <span class="nav__icon" aria-hidden="true">&#9677;</span>
                                Users
                            </a>
                        </li>
                    @endcan
                    @can('manage_users')
                        <li>
                            <a class="nav__link" href="{{ route('admin.roles.index') }}"
                               @if ($active('admin.roles.*')) aria-current="page" @endif>
                                <span class="nav__icon" aria-hidden="true">&#9635;</span>
                                Roles &amp; permissions
                            </a>
                        </li>
                    @endcan
                    @can('manage_settings')
                        <li>
                            <a class="nav__link" href="{{ route('admin.settings.index') }}"
                               @if ($active('admin.settings.*')) aria-current="page" @endif>
                                <span class="nav__icon" aria-hidden="true">&#9881;</span>
                                Settings
                            </a>
                        </li>
                    @endcan
                </ul>
            </div>
        @endcanany
    </nav>

    <div class="sidebar__foot">
        AutoTransport admin
        <span aria-hidden="true">&middot;</span>
        {{ config('app.env') === 'production' ? 'live' : config('app.env') }}
    </div>
</aside>
