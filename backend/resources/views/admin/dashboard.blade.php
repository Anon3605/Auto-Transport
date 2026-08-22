@extends('layouts.admin')

@section('page_title', 'Dashboard')

@section('content')
    @php
        /*
         * Tone is derived from the label so the controller can add a tile
         * without touching this view; an explicit 'tone' key still wins.
         */
        $toneFor = function (string $label): string {
            $l = Str::lower($label);

            return match (true) {
                Str::contains($l, ['cancel', 'fail', 'reject', 'overdue', 'locked', 'spam']) => 'danger',
                Str::contains($l, ['pending', 'awaiting', 'unpaid', 'unread', 'open', 'new']) => 'warning',
                Str::contains($l, ['delivered', 'revenue', 'paid', 'approved', 'active', 'complete']) => 'success',
                Str::contains($l, ['transit', 'quote', 'booking', 'message', 'lead']) => 'info',
                default => '',
            };
        };

        // Counts arrive as ints, money pre-formatted as a string. Only touch numbers.
        $formatStat = function ($value): string {
            if ($value === null || $value === '') {
                return '—';
            }

            return is_numeric($value)
                ? number_format((float) $value, str_contains((string) $value, '.') ? 2 : 0)
                : (string) $value;
        };

        $statusTotal = collect($statusCounts)->sum();
        $statusPeak = max(1, (int) collect($statusCounts)->max());
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                Operational snapshot for {{ now()->timezone(auth()->user()?->timezone ?: config('app.timezone'))->format('l j F Y') }}.
            </p>
        </div>
        <div class="page-head__actions">
            @can('view_bookings')
                <a class="btn btn--secondary btn--sm" href="{{ route('admin.bookings.index') }}">All bookings</a>
            @endcan
            @can('view_reviews')
                <a class="btn btn--primary btn--sm" href="{{ route('admin.reviews.index', ['status' => 'pending']) }}">
                    Moderation queue
                </a>
            @endcan
        </div>
    </div>

    <div class="stack">
        @if (filled($stats))
            <div class="grid grid-4">
                @foreach ($stats as $label => $stat)
                    @php
                        $tone = $stat['tone'] ?? $toneFor((string) $label);
                    @endphp
                    <article class="stat-tile {{ $tone !== '' ? 'stat-tile--' . $tone : '' }}">
                        <h2 class="stat-tile__label">{{ $label }}</h2>
                        <p class="stat-tile__value">{{ $formatStat($stat['value'] ?? null) }}</p>
                        @if (! empty($stat['hint']))
                            <p class="stat-tile__hint">{{ $stat['hint'] }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        @can('view_bookings')
            {{--
                The approval queue. Placed above everything else because it is the
                only block on this page with a customer waiting at the other end of
                it — a new order sits at pending_payment until a human confirms it,
                and until this existed nothing on the dashboard said so.
            --}}
            <section class="card card--flush">
                <div class="card__head">
                    <h2 class="card__title">
                        Awaiting confirmation
                        @if (count($awaitingConfirmation) > 0)
                            <span class="badge badge--warning">{{ count($awaitingConfirmation) }}</span>
                        @endif
                    </h2>
                    <div class="card__actions">
                        <a class="btn btn--ghost btn--sm"
                           href="{{ route('admin.bookings.index', ['status' => 'pending_payment']) }}">View all</a>
                    </div>
                </div>

                @if (count($awaitingConfirmation) === 0)
                    @include('admin.partials.empty-state', [
                        'icon' => '✓',
                        'title' => 'Nothing waiting',
                        'text' => 'Every order placed has been confirmed or cancelled.',
                    ])
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th scope="col">Order</th>
                                    <th scope="col">Customer</th>
                                    <th scope="col">Lane</th>
                                    <th scope="col">Placed</th>
                                    <th scope="col" class="num">Total</th>
                                    <th scope="col"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($awaitingConfirmation as $order)
                                    <tr>
                                        <td>
                                            <a class="mono table__primary"
                                               href="{{ route('admin.bookings.show', $order) }}">{{ $order->booking_number }}</a>
                                            <span class="table__sub">{{ $order->service?->name ?? 'No service' }}</span>
                                        </td>
                                        <td>
                                            <span class="table__primary">{{ $order->user?->name ?? 'Deleted user' }}</span>
                                            <span class="table__sub truncate">{{ $order->user?->email }}</span>
                                        </td>
                                        <td>
                                            <span class="lane">
                                                <span class="lane__point">{{ $order->pickup_city }}</span>
                                                <span class="lane__arrow" aria-hidden="true">&rarr;</span>
                                                <span class="lane__point">{{ $order->dropoff_city }}</span>
                                            </span>
                                        </td>
                                        <td class="nowrap" title="{{ $order->created_at }}">
                                            {{ $order->created_at?->diffForHumans() }}
                                        </td>
                                        <td class="num money">
                                            {{ number_format($order->total_price['cents'] / 100, 2) }}
                                        </td>
                                        <td class="nowrap">
                                            @can('manage_bookings')
                                                {{-- Posts to the same status endpoint the detail page uses,
                                                     so the state machine and the timeline event are shared
                                                     rather than reimplemented for a shortcut. --}}
                                                <form method="POST"
                                                      action="{{ route('admin.bookings.status', $order) }}"
                                                      class="inline-form">
                                                    @csrf
                                                    <input type="hidden" name="status" value="confirmed">
                                                    <input type="hidden" name="description"
                                                           value="Confirmed from the dashboard queue.">
                                                    <button type="submit" class="btn btn--outline-success btn--sm">
                                                        Confirm
                                                    </button>
                                                </form>
                                            @else
                                                <a class="btn btn--secondary btn--sm"
                                                   href="{{ route('admin.bookings.show', $order) }}">Open</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endcan

        <div class="split">
            <section class="card card--flush">
                <div class="card__head">
                    <h2 class="card__title">Recent bookings</h2>
                    @can('view_bookings')
                        <div class="card__actions">
                            <a class="btn btn--ghost btn--sm" href="{{ route('admin.bookings.index') }}">View all</a>
                        </div>
                    @endcan
                </div>

                @if (count($recentBookings) === 0)
                    @include('admin.partials.empty-state', [
                        'icon' => '▤',
                        'title' => 'No bookings yet',
                        'text' => 'Accepted quotes become bookings and land here first.',
                    ])
                @else
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <caption class="sr-only">The ten most recent bookings, newest first</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Booking</th>
                                    <th scope="col">Customer</th>
                                    <th scope="col">Lane</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentBookings as $booking)
                                    <tr>
                                        <td>
                                            @can('view_bookings')
                                                <a class="table__primary mono"
                                                   href="{{ route('admin.bookings.show', $booking) }}">{{ $booking->booking_number }}</a>
                                            @else
                                                <span class="mono strong">{{ $booking->booking_number }}</span>
                                            @endcan
                                            <span class="table__sub">{{ $booking->created_at?->diffForHumans() }}</span>
                                        </td>
                                        <td>
                                            <span class="truncate">{{ $booking->user?->name ?? 'Deleted account' }}</span>
                                        </td>
                                        <td>
                                            <span class="lane">
                                                <span class="lane__point">{{ $booking->pickup_city }}, {{ $booking->pickup_state }}</span>
                                                <span class="lane__arrow" aria-hidden="true">&rarr;</span>
                                                <span class="sr-only">to</span>
                                                <span class="lane__point">{{ $booking->dropoff_city }}, {{ $booking->dropoff_state }}</span>
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

            <div class="stack">
                <section class="card">
                    <div class="card__head">
                        <h2 class="card__title">Booking mix</h2>
                        <div class="card__actions">
                            <span class="chip">{{ number_format($statusTotal) }} total</span>
                        </div>
                    </div>
                    <div class="card__body">
                        {{-- Every case is listed, zeros included: a status that
                             vanishes from the chart reads as "no data", not "none". --}}
                        <div class="bars">
                            @foreach (\App\Enums\BookingStatus::cases() as $case)
                                @php
                                    $count = (int) ($statusCounts[$case->value] ?? 0);
                                @endphp
                                <div class="bar-row">
                                    <span class="bar-row__label">{{ $case->label() }}</span>
                                    <span class="bar">
                                        <span class="bar__fill is-{{ $case->color() }}"
                                              style="width: {{ $count > 0 ? round(($count / $statusPeak) * 100, 1) : 0 }}%"></span>
                                    </span>
                                    <span class="bar-row__value">{{ number_format($count) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="card card--flush">
                    <div class="card__head">
                        <h2 class="card__title">Awaiting moderation</h2>
                        @can('view_reviews')
                            <div class="card__actions">
                                <a class="btn btn--ghost btn--sm"
                                   href="{{ route('admin.reviews.index', ['status' => 'pending']) }}">Queue</a>
                            </div>
                        @endcan
                    </div>

                    @if (count($pendingReviews) === 0)
                        @include('admin.partials.empty-state', [
                            'icon' => '★',
                            'title' => 'Queue is clear',
                            'text' => 'No reviews are waiting on a decision.',
                        ])
                    @else
                        <ul class="review-list">
                            @foreach ($pendingReviews as $review)
                                <li class="review-item">
                                    <div class="review-item__main">
                                        <div class="review-item__head">
                                            @include('admin.partials.stars', [
                                                'value' => $review->rating_overall,
                                                'size' => 'sm',
                                                'showValue' => false,
                                            ])
                                            @can('view_reviews')
                                                <a class="review-item__title small"
                                                   href="{{ route('admin.reviews.show', $review) }}">
                                                    {{ $review->title ?: 'Untitled review' }}
                                                </a>
                                            @else
                                                <span class="small strong">{{ $review->title ?: 'Untitled review' }}</span>
                                            @endcan
                                        </div>
                                        <p class="review-item__foot">
                                            <span>{{ $review->author_name }}</span>
                                            <span aria-hidden="true">&middot;</span>
                                            <span>{{ $review->created_at?->diffForHumans() }}</span>
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </div>
@endsection
