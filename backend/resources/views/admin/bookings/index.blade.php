@extends('layouts.admin')

@section('page_title', 'Bookings')

@section('breadcrumb')
    <li aria-current="page">Bookings</li>
@endsection

@section('content')
    @php
        $currentStatus = (string) request('status', '');
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                The dispatch board. Filter by pickup window to plan a day, or search a
                booking number a customer has read out over the phone.
            </p>
        </div>
    </div>

    <section class="card card--flush">
        {{--
            GET, not POST: a filtered board is a URL a dispatcher bookmarks and
            shares. Keeping it in the query string is also what lets the paginator
            partial carry the filters to page 2.
        --}}
        <form method="GET" action="{{ route('admin.bookings.index') }}" class="filter-bar">
            <div class="form-field">
                <label class="form-label" for="filter-q">Search</label>
                <input class="input" type="search" name="q" id="filter-q"
                       value="{{ request('q') }}"
                       placeholder="Booking number, customer, city">
            </div>

            <div class="form-field">
                <label class="form-label" for="filter-status">Status</label>
                <select class="select" name="status" id="filter-status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($currentStatus === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <label class="form-label" for="filter-from">Pickup from</label>
                <input class="input" type="date" name="from" id="filter-from" value="{{ request('from') }}">
            </div>

            <div class="form-field">
                <label class="form-label" for="filter-to">Pickup to</label>
                <input class="input" type="date" name="to" id="filter-to" value="{{ request('to') }}">
            </div>

            <div class="filter-bar__actions">
                <button type="submit" class="btn btn--primary">Filter</button>
                @if (request()->hasAny(['q', 'status', 'from', 'to']))
                    <a class="btn btn--ghost" href="{{ route('admin.bookings.index') }}">Clear</a>
                @endif
            </div>
        </form>

        @if (count($bookings) === 0)
            @include('admin.partials.empty-state', [
                'icon' => '◇',
                'title' => 'No bookings match',
                'text' => 'Nothing on the board for this filter. Accepted quotes appear here as bookings.',
                'actionUrl' => request()->hasAny(['q', 'status', 'from', 'to']) ? route('admin.bookings.index') : null,
                'actionLabel' => 'Show all bookings',
            ])
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Booking</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Lane</th>
                            <th scope="col">Pickup</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Total</th>
                            <th scope="col" class="num">Balance</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr>
                                <td>
                                    <a class="mono table__primary" href="{{ route('admin.bookings.show', $booking) }}">
                                        {{ $booking->booking_number }}
                                    </a>
                                    <span class="table__sub">{{ $booking->service?->name ?? 'No service' }}</span>
                                </td>

                                <td>
                                    <span class="table__primary">{{ $booking->user?->name ?? 'Deleted user' }}</span>
                                    <span class="table__sub truncate">{{ $booking->user?->email }}</span>
                                </td>

                                <td>
                                    <span class="lane">
                                        <span class="lane__point">{{ $booking->pickup_city }}{{ $booking->pickup_state ? ', '.$booking->pickup_state : '' }}</span>
                                        <span class="lane__arrow" aria-hidden="true">&rarr;</span>
                                        <span class="lane__point">{{ $booking->dropoff_city }}{{ $booking->dropoff_state ? ', '.$booking->dropoff_state : '' }}</span>
                                    </span>
                                    @if ($booking->distance_miles)
                                        <span class="table__sub">{{ number_format((float) $booking->distance_miles) }} mi</span>
                                    @endif
                                </td>

                                <td class="nowrap">
                                    {{ $booking->scheduled_pickup_date?->format('D j M Y') ?? '—' }}
                                    @if ($booking->actual_pickup_at)
                                        <span class="table__sub">Picked up {{ $booking->actual_pickup_at->format('j M') }}</span>
                                    @endif
                                </td>

                                <td>@include('admin.partials.status-badge', ['status' => $booking->status])</td>

                                {{-- Money is integer minor units end to end (§4.4); the accessor
                                     returns the {cents,currency} shape, never a float. --}}
                                <td class="num money">
                                    <span class="money__cur">{{ $booking->total_price['currency'] }}</span>
                                    {{ number_format($booking->total_price['cents'] / 100, 2) }}
                                </td>

                                <td class="num money">
                                    @if ($booking->balance_due['cents'] > 0)
                                        <span class="money__cur">{{ $booking->balance_due['currency'] }}</span>
                                        <span class="strong">{{ number_format($booking->balance_due['cents'] / 100, 2) }}</span>
                                    @else
                                        <span class="badge badge--success">Paid</span>
                                    @endif
                                </td>

                                <td class="nowrap">
                                    <a class="btn btn--secondary btn--sm" href="{{ route('admin.bookings.show', $booking) }}">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('admin.partials.pagination', ['paginator' => $bookings])
        @endif
    </section>
@endsection
