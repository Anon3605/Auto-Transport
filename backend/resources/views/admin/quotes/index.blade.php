@extends('layouts.admin')

@section('page_title', 'Quote requests')

@section('breadcrumb')
    <li aria-current="page">Quote requests</li>
@endsection

@section('content')
    @php
        $currentStatus = (string) request('status', '');
        $currentAssigned = (string) request('assigned', '');
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                Incoming leads. This is append-only intake — pricing a request issues a
                versioned quote rather than overwriting what the customer was told (§4.1).
            </p>
        </div>
    </div>

    <section class="card card--flush">
        <form method="GET" action="{{ route('admin.quotes.index') }}" class="filter-bar">
            <div class="form-field">
                <label class="form-label" for="filter-q">Search</label>
                <input class="input" type="search" name="q" id="filter-q"
                       value="{{ request('q') }}"
                       placeholder="Reference, name, email, phone">
            </div>

            <div class="form-field">
                <label class="form-label" for="filter-status">Status</label>
                <select class="select" name="status" id="filter-status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($currentStatus === $status->value)>
                            {{ Str::headline($status->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <label class="form-label" for="filter-assigned">Assignee</label>
                <select class="select" name="assigned" id="filter-assigned">
                    <option value="">Anyone</option>
                    <option value="unassigned" @selected($currentAssigned === 'unassigned')>Unassigned</option>
                    @auth
                        <option value="{{ auth()->id() }}" @selected($currentAssigned === (string) auth()->id())>
                            My leads
                        </option>
                    @endauth
                </select>
            </div>

            <div class="filter-bar__actions">
                <button type="submit" class="btn btn--primary">Filter</button>
                @if (request()->hasAny(['q', 'status', 'assigned']))
                    <a class="btn btn--ghost" href="{{ route('admin.quotes.index') }}">Clear</a>
                @endif
            </div>
        </form>

        @if (count($quoteRequests) === 0)
            @include('admin.partials.empty-state', [
                'icon' => '✎',
                'title' => 'No quote requests match',
                'text' => 'New submissions from the website and the app land here.',
                'actionUrl' => request()->hasAny(['q', 'status', 'assigned']) ? route('admin.quotes.index') : null,
                'actionLabel' => 'Show all requests',
            ])
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Lane</th>
                            <th scope="col" class="num">Vehicles</th>
                            <th scope="col">Pickup window</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Estimate</th>
                            <th scope="col">Assignee</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($quoteRequests as $quoteRequest)
                            <tr>
                                <td>
                                    <a class="mono table__primary" href="{{ route('admin.quotes.show', $quoteRequest) }}">
                                        {{ $quoteRequest->reference }}
                                    </a>
                                    <span class="table__sub">{{ $quoteRequest->created_at?->diffForHumans() }}</span>
                                </td>

                                <td>
                                    <span class="table__primary">{{ $quoteRequest->contact_name }}</span>
                                    <span class="table__sub truncate">{{ $quoteRequest->contact_email }}</span>
                                    @unless ($quoteRequest->user_id)
                                        {{-- Guests may request a price; forcing a signup first is the
                                             conversion killer §4.10 warns about. --}}
                                        <span class="chip">Guest</span>
                                    @endunless
                                </td>

                                <td>
                                    <span class="lane">
                                        <span class="lane__point">{{ $quoteRequest->pickup_city }}{{ $quoteRequest->pickup_state ? ', '.$quoteRequest->pickup_state : '' }}</span>
                                        <span class="lane__arrow" aria-hidden="true">&rarr;</span>
                                        <span class="lane__point">{{ $quoteRequest->dropoff_city }}{{ $quoteRequest->dropoff_state ? ', '.$quoteRequest->dropoff_state : '' }}</span>
                                    </span>
                                    @if ($quoteRequest->distance_miles)
                                        <span class="table__sub">{{ number_format((float) $quoteRequest->distance_miles) }} mi</span>
                                    @endif
                                </td>

                                {{-- Denormalised counter, kept by an observer, so the list does not
                                     run a correlated subquery per row (§4.2). --}}
                                <td class="num">{{ $quoteRequest->vehicle_count }}</td>

                                <td class="nowrap">
                                    {{ $quoteRequest->pickup_date_earliest?->format('j M') ?? '—' }}
                                    @if ($quoteRequest->pickup_date_latest)
                                        &ndash; {{ $quoteRequest->pickup_date_latest->format('j M') }}
                                    @endif
                                    @if ($quoteRequest->dates_flexible)
                                        <span class="table__sub">Flexible</span>
                                    @endif
                                </td>

                                <td>
                                    @include('admin.partials.status-badge', ['status' => $quoteRequest->status])
                                    @if ($quoteRequest->quotes_count > 0)
                                        <span class="table__sub">{{ $quoteRequest->quotes_count }} {{ Str::plural('quote', $quoteRequest->quotes_count) }}</span>
                                    @endif
                                </td>

                                <td class="num money">
                                    @if ($quoteRequest->estimated_price_cents)
                                        {{ number_format($quoteRequest->estimated_price_cents / 100, 2) }}
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>

                                <td>{{ $quoteRequest->assignee?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('admin.partials.pagination', ['paginator' => $quoteRequests])
        @endif
    </section>
@endsection
