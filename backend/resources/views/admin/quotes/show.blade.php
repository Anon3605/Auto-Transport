@extends('layouts.admin')

@section('page_title', $quoteRequest->reference)

@section('breadcrumb')
    <li><a href="{{ route('admin.quotes.index') }}">Quote requests</a></li>
    <li aria-current="page">{{ $quoteRequest->reference }}</li>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                <span class="ref mono">{{ $quoteRequest->reference }}</span>
                @include('admin.partials.status-badge', ['status' => $quoteRequest->status, 'size' => 'lg'])
            </p>
        </div>
    </div>

    <div class="split">
        <div class="main-col stack">

            <section class="card">
                <div class="card__head"><h2 class="card__title">Requested move</h2></div>
                <div class="card__body">
                    <div class="grid grid-2">
                        <div>
                            <h3 class="section-head">Pickup</h3>
                            <dl class="kv">
                                <dt>Address</dt>
                                <dd>
                                    {{ $quoteRequest->pickup_line1 }}
                                    <br>{{ $quoteRequest->pickup_city }}{{ $quoteRequest->pickup_state ? ', '.$quoteRequest->pickup_state : '' }}
                                    {{ $quoteRequest->pickup_postal_code }}
                                </dd>
                                <dt>Type</dt>
                                <dd>{{ Str::headline($quoteRequest->pickup_location_type) }}</dd>
                            </dl>
                        </div>
                        <div>
                            <h3 class="section-head">Delivery</h3>
                            <dl class="kv">
                                <dt>Address</dt>
                                <dd>
                                    {{ $quoteRequest->dropoff_line1 }}
                                    <br>{{ $quoteRequest->dropoff_city }}{{ $quoteRequest->dropoff_state ? ', '.$quoteRequest->dropoff_state : '' }}
                                    {{ $quoteRequest->dropoff_postal_code }}
                                </dd>
                                <dt>Type</dt>
                                <dd>{{ Str::headline($quoteRequest->dropoff_location_type) }}</dd>
                            </dl>
                        </div>
                    </div>

                    <dl class="kv">
                        <dt>Pickup window</dt>
                        <dd>
                            {{ $quoteRequest->pickup_date_earliest?->format('D j M Y') ?? '—' }}
                            @if ($quoteRequest->pickup_date_latest)
                                &ndash; {{ $quoteRequest->pickup_date_latest->format('D j M Y') }}
                            @endif
                            @if ($quoteRequest->dates_flexible)
                                <span class="badge badge--info">Flexible</span>
                            @endif
                        </dd>
                        <dt>Distance</dt>
                        <dd>{{ $quoteRequest->distance_miles ? number_format((float) $quoteRequest->distance_miles).' mi' : 'Not calculated' }}</dd>
                        <dt>Service</dt>
                        <dd>{{ $quoteRequest->service?->name ?? 'Not specified' }}</dd>
                    </dl>

                    @if ($quoteRequest->additional_notes)
                        <h3 class="section-head">Customer notes</h3>
                        <p class="prose">{{ $quoteRequest->additional_notes }}</p>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Vehicles ({{ $quoteRequest->vehicles->count() }})</h2>
                </div>
                @if ($quoteRequest->vehicles->isEmpty())
                    @include('admin.partials.empty-state', ['icon' => '⛌', 'title' => 'No vehicles listed'])
                @else
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <thead>
                                <tr>
                                    <th scope="col">Vehicle</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">VIN</th>
                                    <th scope="col">Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($quoteRequest->vehicles as $vehicle)
                                    <tr>
                                        <td class="table__primary">
                                            {{ trim("{$vehicle->year} {$vehicle->make} {$vehicle->model}") ?: 'Unspecified' }}
                                            @if ($vehicle->trim)
                                                <span class="table__sub">{{ $vehicle->trim }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $vehicle->vehicleType?->name ?? '—' }}</td>
                                        <td class="mono">{{ $vehicle->vin ?: '—' }}</td>
                                        <td>
                                            @if ($vehicle->is_operable)
                                                <span class="badge badge--success">Operable</span>
                                            @else
                                                <span class="badge badge--warning">Needs winch</span>
                                            @endif
                                            @if ($vehicle->is_modified)
                                                <span class="badge badge--info">Modified</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Quote versions ---------------------------------------------- --}}
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Quotes issued</h2>
                    <p class="card__actions small muted">Newest version first</p>
                </div>

                @if ($quoteRequest->quotes->isEmpty())
                    @include('admin.partials.empty-state', [
                        'icon' => '◫',
                        'title' => 'Not priced yet',
                        'text' => 'Issuing a quote creates a new versioned offer; re-pricing supersedes it rather than overwriting.',
                    ])
                @else
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <thead>
                                <tr>
                                    <th scope="col">Ver.</th>
                                    <th scope="col">Reference</th>
                                    <th scope="col" class="num">Total</th>
                                    <th scope="col" class="num">Deposit</th>
                                    <th scope="col">Valid until</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Issued by</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($quoteRequest->quotes as $quote)
                                    <tr>
                                        <td class="num mono">{{ $quote->version }}</td>
                                        <td class="mono">{{ $quote->reference }}</td>
                                        <td class="num money">{{ number_format($quote->total_price_cents / 100, 2) }}</td>
                                        <td class="num money">{{ number_format($quote->deposit_cents / 100, 2) }}</td>
                                        <td class="nowrap">
                                            {{ $quote->valid_until?->format('j M Y') ?? '—' }}
                                            @if ($quote->valid_until && $quote->valid_until->isPast())
                                                <span class="table__sub">Lapsed</span>
                                            @endif
                                        </td>
                                        <td>@include('admin.partials.status-badge', ['status' => $quote->status])</td>
                                        <td>{{ $quote->issuedBy?->name ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Contact</h2></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Name</dt>
                        <dd>{{ $quoteRequest->contact_name }}</dd>
                        <dt>Email</dt>
                        <dd class="truncate"><a href="mailto:{{ $quoteRequest->contact_email }}">{{ $quoteRequest->contact_email }}</a></dd>
                        <dt>Phone</dt>
                        <dd>
                            @if ($quoteRequest->contact_phone)
                                <a href="tel:{{ $quoteRequest->contact_phone }}">{{ $quoteRequest->contact_phone }}</a>
                            @else — @endif
                        </dd>
                        <dt>Account</dt>
                        <dd>
                            @if ($quoteRequest->user)
                                @can('view_users')
                                    <a href="{{ route('admin.users.show', $quoteRequest->user) }}">{{ $quoteRequest->user->name }}</a>
                                @else
                                    {{ $quoteRequest->user->name }}
                                @endcan
                            @else
                                <span class="chip">Guest submission</span>
                            @endif
                        </dd>
                        <dt>Assignee</dt>
                        <dd>{{ $quoteRequest->assignee?->name ?? 'Unassigned' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Instant estimate</h2></div>
                <div class="card__body">
                    @if ($quoteRequest->estimated_price_cents)
                        <p class="stat-tile__value money">
                            {{ $quoteRequest->currency }} {{ number_format($quoteRequest->estimated_price_cents / 100, 2) }}
                        </p>
                        {{-- Marketing number, not a commitment. A human issues the binding
                             quote; this is what the website showed at submission (§4.1). --}}
                        <p class="form-hint">
                            The automated figure shown on the website at submission. Subject to
                            confirmation — it is not an offer.
                        </p>
                    @else
                        <p class="muted">No automated estimate was recorded.</p>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Provenance</h2></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Source</dt>
                        <dd>{{ Str::headline($quoteRequest->source ?? 'web') }}</dd>
                        <dt>Submitted</dt>
                        <dd>{{ $quoteRequest->created_at?->format('j M Y H:i') }}</dd>
                        <dt>Expires</dt>
                        <dd>{{ $quoteRequest->expires_at?->format('j M Y') ?? '—' }}</dd>
                        @if ($quoteRequest->spam_score)
                            <dt>Spam score</dt>
                            <dd>{{ $quoteRequest->spam_score }}</dd>
                        @endif
                        <dt>IP</dt>
                        {{-- Retained for abuse forensics only; it is personal data under
                             GDPR and should be pruned on a schedule (§4.12). --}}
                        <dd class="mono tiny">{{ $quoteRequest->ip_address ?? '—' }}</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>
@endsection
