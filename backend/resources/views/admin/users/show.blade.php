@extends('layouts.admin')

@section('page_title', $user->name)

@section('breadcrumb')
    <li><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li aria-current="page">{{ Str::limit($user->name, 32) }}</li>
@endsection

@section('content')
    @php
        $initials = collect(preg_split('/\s+/', trim((string) $user->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('') ?: mb_strtoupper(mb_substr((string) $user->email, 0, 1));

        /*
         * The route hands this view a bare $user, so the activity panels below
         * read their own rows. Kept to counts plus one short list -- anything
         * heavier belongs in the controller.
         */
        $bookingCount = $user->bookings()->count();
        $reviewCount = $user->reviews()->count();
        $quoteRequestCount = $user->quoteRequests()->count();
        $recentBookings = $user->bookings()->latest()->limit(5)->get();
        $roleNames = $user->getRoleNames();
        $driver = $user->driverProfile;
        $addresses = $user->addresses;
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                <span class="ref">{{ $user->ulid }}</span>
                joined {{ $user->created_at?->format('j M Y') }}
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.users.index') }}">Back to users</a>
            @can('manage_users')
                <a class="btn btn--primary" href="{{ route('admin.users.edit', $user) }}">Edit user</a>
            @endcan
        </div>
    </div>

    <div class="split">
        <div class="stack">
            <section class="card">
                <div class="card__body">
                    <div class="row">
                        <span class="avatar avatar--xl avatar--brand" aria-hidden="true">
                            @if ($user->avatar_url)
                                <img src="{{ $user->avatar_url }}" alt="">
                            @else
                                {{ $initials }}
                            @endif
                        </span>
                        <div class="stack-sm">
                            <h2>{{ $user->name }}</h2>
                            <p class="small muted">
                                <a href="mailto:{{ $user->email }}">{{ $user->email }}</a>
                                @if ($user->phone)
                                    <span aria-hidden="true">&middot;</span>
                                    <a href="tel:{{ $user->phone_normalized ?: $user->phone }}">{{ $user->phone }}</a>
                                @endif
                            </p>
                            <div class="row-tight">
                                @include('admin.partials.status-badge', ['status' => $user->status])
                                @if ($user->deleted_at)
                                    <span class="badge badge--danger">Deleted</span>
                                @endif
                                @if ($user->isLocked())
                                    <span class="badge badge--danger">Locked</span>
                                @endif
                                <span class="badge {{ $user->email_verified_at ? 'badge--success' : 'badge--warning' }}">
                                    {{ $user->email_verified_at ? 'Email verified' : 'Email unverified' }}
                                </span>
                                @if ($user->two_factor_confirmed_at)
                                    <span class="badge badge--info">2FA on</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid grid-3">
                <article class="stat-tile stat-tile--info">
                    <h2 class="stat-tile__label">Bookings</h2>
                    <p class="stat-tile__value">{{ number_format($bookingCount) }}</p>
                </article>
                <article class="stat-tile">
                    <h2 class="stat-tile__label">Quote requests</h2>
                    <p class="stat-tile__value">{{ number_format($quoteRequestCount) }}</p>
                </article>
                <article class="stat-tile stat-tile--success">
                    <h2 class="stat-tile__label">Reviews</h2>
                    <p class="stat-tile__value">{{ number_format($reviewCount) }}</p>
                </article>
            </div>

            @can('view_bookings')
                <section class="card card--flush">
                    <div class="card__head">
                        <h2 class="card__title">Recent bookings</h2>
                        <div class="card__actions">
                            <a class="btn btn--ghost btn--sm" href="{{ route('admin.bookings.index', ['q' => $user->email]) }}">
                                All for this customer
                            </a>
                        </div>
                    </div>

                    @if ($recentBookings->isEmpty())
                        @include('admin.partials.empty-state', [
                            'icon' => '▤',
                            'title' => 'No bookings',
                            'text' => 'This account has never shipped a vehicle.',
                        ])
                    @else
                        <div class="table-wrap">
                            <table class="table table--compact">
                                <caption class="sr-only">Five most recent bookings for {{ $user->name }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Booking</th>
                                        <th scope="col">Lane</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="num">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recentBookings as $booking)
                                        <tr>
                                            <th scope="row">
                                                <a class="table__primary mono" href="{{ route('admin.bookings.show', $booking) }}">
                                                    {{ $booking->booking_number }}
                                                </a>
                                                <span class="table__sub">{{ $booking->created_at?->format('j M Y') }}</span>
                                            </th>
                                            <td>
                                                <span class="lane">
                                                    <span class="lane__point">{{ $booking->pickup_city }}</span>
                                                    <span class="lane__arrow" aria-hidden="true">&rarr;</span>
                                                    <span class="sr-only">to</span>
                                                    <span class="lane__point">{{ $booking->dropoff_city }}</span>
                                                </span>
                                            </td>
                                            <td>@include('admin.partials.status-badge', ['status' => $booking->status])</td>
                                            <td class="num">
                                                <span class="money">{{ number_format($booking->total_price_cents / 100, 2) }}<span class="money__cur">{{ $booking->currency }}</span></span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endcan

            @if ($addresses->isNotEmpty())
                <section class="card card--flush">
                    <div class="card__head">
                        <h2 class="card__title">Saved addresses</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <caption class="sr-only">Addresses saved by {{ $user->name }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Label</th>
                                    <th scope="col">Address</th>
                                    <th scope="col">Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($addresses as $address)
                                    <tr>
                                        <th scope="row">
                                            {{ $address->label ?: '—' }}
                                            @if ($address->is_default)
                                                <span class="table__sub">Default</span>
                                            @endif
                                        </th>
                                        <td>
                                            {{ $address->line1 }}@if ($address->line2), {{ $address->line2 }}@endif,
                                            {{ $address->city }} {{ $address->state }} {{ $address->postal_code }}
                                        </td>
                                        <td><span class="chip">{{ Str::headline($address->location_type) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Roles</h2>
                </div>
                <div class="card__body">
                    @if ($roleNames->isEmpty())
                        <p class="small muted">No roles assigned. This account cannot reach the panel.</p>
                    @else
                        <div class="stack-sm">
                            <div class="row-tight">
                                @foreach ($roleNames as $roleName)
                                    <span class="badge badge--primary badge--plain">{{ Str::headline($roleName) }}</span>
                                @endforeach
                            </div>
                            <p class="form-hint">
                                {{ $user->isStaff() ? 'Has panel access.' : 'No panel access — app roles only.' }}
                            </p>
                        </div>
                    @endif
                </div>
            </section>

            @if ($driver)
                <section class="card">
                    <div class="card__head">
                        <h2 class="card__title">Driver profile</h2>
                    </div>
                    <div class="card__body">
                        <dl class="kv">
                            <dt>Carrier</dt>
                            <dd>{{ $driver->carrier?->company_name ?? '—' }}</dd>

                            <dt>Available</dt>
                            <dd>
                                <span class="badge {{ $driver->is_available ? 'badge--success' : 'badge--neutral' }}">
                                    {{ $driver->is_available ? 'Available' : 'Unavailable' }}
                                </span>
                            </dd>

                            <dt>Licence expires</dt>
                            <dd>{{ $driver->license_expires_at?->format('j M Y') ?? '—' }}</dd>

                            <dt>Rating</dt>
                            <dd>
                                @include('admin.partials.stars', [
                                    'value' => $driver->rating_avg,
                                    'count' => $driver->rating_count,
                                ])
                            </dd>

                            <dt>Last ping</dt>
                            <dd>{{ $driver->last_ping_at?->diffForHumans() ?? '—' }}</dd>
                        </dl>
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Account &amp; security</h2>
                </div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>ULID</dt>
                        <dd><span class="mono">{{ $user->ulid }}</span></dd>

                        <dt>Email verified</dt>
                        <dd>{{ $user->email_verified_at?->format('j M Y H:i') ?? 'Never' }}</dd>

                        <dt>Phone verified</dt>
                        <dd>{{ $user->phone_verified_at?->format('j M Y H:i') ?? 'Never' }}</dd>

                        <dt>Last sign-in</dt>
                        <dd>
                            {{ $user->last_login_at?->format('j M Y H:i') ?? 'Never' }}
                            @if ($user->last_login_ip)
                                <span class="table__sub mono">{{ $user->last_login_ip }}</span>
                            @endif
                        </dd>

                        <dt>Failed attempts</dt>
                        <dd class="num">{{ (int) $user->failed_login_count }}</dd>

                        <dt>Locked until</dt>
                        <dd>{{ $user->locked_until?->format('j M Y H:i') ?? '—' }}</dd>

                        <dt>Locale</dt>
                        <dd>{{ $user->locale }} <span class="muted">/ {{ $user->timezone }}</span></dd>

                        <dt>Created</dt>
                        <dd>{{ $user->created_at?->format('j M Y H:i') }}</dd>

                        <dt>Updated</dt>
                        <dd>{{ $user->updated_at?->format('j M Y H:i') }}</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>
@endsection
