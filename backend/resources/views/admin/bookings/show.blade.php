@extends('layouts.admin')

@section('page_title', $booking->booking_number)

@section('breadcrumb')
    <li><a href="{{ route('admin.bookings.index') }}">Bookings</a></li>
    <li aria-current="page">{{ $booking->booking_number }}</li>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                <span class="ref mono">{{ $booking->booking_number }}</span>
                @include('admin.partials.status-badge', ['status' => $booking->status, 'size' => 'lg'])
            </p>
        </div>
    </div>

    <div class="split">
        <div class="main-col stack">

            {{-- Route ------------------------------------------------------ --}}
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Route</h2>
                </div>
                <div class="card__body">
                    <div class="grid grid-2">
                        <div>
                            <h3 class="section-head">Pickup</h3>
                            <dl class="kv">
                                <dt>Contact</dt>
                                <dd>{{ $booking->pickup_contact_name ?: '—' }}</dd>
                                <dt>Phone</dt>
                                <dd>{{ $booking->pickup_contact_phone ?: '—' }}</dd>
                                <dt>Address</dt>
                                <dd>
                                    {{ $booking->pickup_line1 }}
                                    @if ($booking->pickup_line2)<br>{{ $booking->pickup_line2 }}@endif
                                    <br>{{ $booking->pickup_city }}{{ $booking->pickup_state ? ', '.$booking->pickup_state : '' }}
                                    {{ $booking->pickup_postal_code }}
                                </dd>
                                <dt>Scheduled</dt>
                                <dd>{{ $booking->scheduled_pickup_date?->format('D j M Y') ?? '—' }}</dd>
                                <dt>Actual</dt>
                                <dd>{{ $booking->actual_pickup_at?->format('D j M Y H:i') ?? 'Not yet' }}</dd>
                            </dl>
                        </div>

                        <div>
                            <h3 class="section-head">Delivery</h3>
                            <dl class="kv">
                                <dt>Contact</dt>
                                <dd>{{ $booking->dropoff_contact_name ?: '—' }}</dd>
                                <dt>Phone</dt>
                                <dd>{{ $booking->dropoff_contact_phone ?: '—' }}</dd>
                                <dt>Address</dt>
                                <dd>
                                    {{ $booking->dropoff_line1 }}
                                    @if ($booking->dropoff_line2)<br>{{ $booking->dropoff_line2 }}@endif
                                    <br>{{ $booking->dropoff_city }}{{ $booking->dropoff_state ? ', '.$booking->dropoff_state : '' }}
                                    {{ $booking->dropoff_postal_code }}
                                </dd>
                                <dt>Scheduled</dt>
                                <dd>{{ $booking->scheduled_delivery_date?->format('D j M Y') ?? '—' }}</dd>
                                <dt>Actual</dt>
                                <dd>{{ $booking->actual_delivery_at?->format('D j M Y H:i') ?? 'Not yet' }}</dd>
                            </dl>
                        </div>
                    </div>

                    {{-- These are flat copies, not a join. A booking must keep the
                         address it actually shipped to even if the customer later
                         edits their saved address book (§4.3). --}}
                    <p class="form-hint">
                        Addresses are snapshotted onto the booking at creation, so this record
                        cannot be rewritten by a later edit to the customer's saved addresses.
                    </p>
                </div>
            </section>

            {{-- Vehicles ---------------------------------------------------- --}}
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Vehicles ({{ $booking->vehicles->count() }})</h2>
                </div>

                @if ($booking->vehicles->isEmpty())
                    @include('admin.partials.empty-state', ['icon' => '⛌', 'title' => 'No vehicles recorded'])
                @else
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <thead>
                                <tr>
                                    <th scope="col">Vehicle</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">VIN</th>
                                    <th scope="col">Runs</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($booking->vehicles as $vehicle)
                                    <tr>
                                        <td class="table__primary">
                                            {{ trim("{$vehicle->year} {$vehicle->make} {$vehicle->model}") ?: 'Unspecified' }}
                                            @if ($vehicle->color)
                                                <span class="table__sub">{{ $vehicle->color }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $vehicle->vehicleType?->name ?? '—' }}</td>
                                        <td class="mono">{{ $vehicle->vin ?: '—' }}</td>
                                        <td>
                                            @if ($vehicle->is_operable)
                                                <span class="badge badge--success">Operable</span>
                                            @else
                                                {{-- Winch required: a material pricing input, not a note. --}}
                                                <span class="badge badge--warning">Inoperable</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Timeline ---------------------------------------------------- --}}
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Timeline</h2>
                    <p class="card__actions small muted">Internal entries included</p>
                </div>
                <div class="card__body">
                    @if ($events->isEmpty())
                        @include('admin.partials.empty-state', ['icon' => '◷', 'title' => 'No events yet'])
                    @else
                        <ol class="timeline">
                            @foreach ($events as $event)
                                <li class="timeline__item">
                                    <span class="timeline__dot" aria-hidden="true"></span>
                                    <div class="timeline__body">
                                        <p class="timeline__head">
                                            <span class="timeline__title">
                                                {{ Str::headline($event->event_type) }}
                                            </span>
                                            @if ($event->to_status)
                                                @include('admin.partials.status-badge', ['status' => $event->to_status])
                                            @endif
                                            @unless ($event->is_customer_visible)
                                                {{-- Dispatch notes ride the same table; the API filters
                                                     them out, the panel is the other audience (§4.8). --}}
                                                <span class="chip">Internal</span>
                                            @endunless
                                        </p>

                                        @if ($event->description)
                                            <p>{{ $event->description }}</p>
                                        @endif

                                        <p class="timeline__meta">
                                            <time class="timeline__time" datetime="{{ $event->occurred_at?->toIso8601String() }}">
                                                {{ $event->occurred_at?->format('j M Y H:i') }}
                                            </time>
                                            @if ($event->createdBy)
                                                <span>by {{ $event->createdBy->name }}</span>
                                            @endif
                                            @if ($event->lat && $event->lng)
                                                <span class="mono tiny">{{ $event->lat }}, {{ $event->lng }}</span>
                                            @endif
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </section>
        </div>

        {{-- Side column --------------------------------------------------- --}}
        <div class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Move status</h2></div>
                <div class="card__body">
                    @can('manage_bookings')
                        @if (count($allowedTransitions) === 0)
                            <p class="muted">
                                {{ $booking->status->label() }} is terminal — there is nowhere left to move.
                            </p>
                        @else
                            {{-- Only the transitions the state machine actually permits are
                                 offered. Rendering the full enum would just manufacture
                                 flash errors on submit. --}}
                            <form method="POST" action="{{ route('admin.bookings.status', $booking) }}" class="stack-sm">
                                @csrf
                                <div class="form-field">
                                    <label class="form-label" for="status">New status</label>
                                    <select class="select" name="status" id="status" required>
                                        @foreach ($allowedTransitions as $next)
                                            <option value="{{ $next->value }}">{{ $next->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-field">
                                    <label class="form-label" for="description">Note</label>
                                    <textarea class="textarea" name="description" id="description" rows="2"
                                              maxlength="500"
                                              placeholder="Added to the timeline."></textarea>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn--primary btn--block">Update status</button>
                                </div>
                            </form>
                        @endif
                    @else
                        <p class="muted">You have read access to this shipment. Moving it requires dispatch rights.</p>
                    @endcan
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Money</h2></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Total</dt>
                        <dd class="money">{{ $booking->currency }} {{ number_format($booking->total_price['cents'] / 100, 2) }}</dd>
                        <dt>Deposit</dt>
                        <dd class="money">{{ $booking->currency }} {{ number_format($booking->deposit['cents'] / 100, 2) }}</dd>
                        <dt>Paid</dt>
                        <dd class="money">{{ $booking->currency }} {{ number_format($booking->amount_paid['cents'] / 100, 2) }}</dd>
                        <dt>Balance</dt>
                        <dd class="money strong">{{ $booking->currency }} {{ number_format($booking->balance_due['cents'] / 100, 2) }}</dd>
                    </dl>

                    @if ($booking->payments->isNotEmpty())
                        <h3 class="section-head">Ledger</h3>
                        <ul class="stack-sm">
                            @foreach ($booking->payments as $payment)
                                <li class="row-tight">
                                    @include('admin.partials.status-badge', ['status' => $payment->status])
                                    <span class="mono">{{ Str::headline($payment->type ?? 'payment') }}</span>
                                    <span class="push money">
                                        {{ number_format($payment->amount_cents / 100, 2) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="form-hint">Refunds are new rows, never edits to a capture (§4.11).</p>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Assignment</h2></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Customer</dt>
                        <dd>
                            @can('view_users')
                                @if ($booking->user)
                                    <a href="{{ route('admin.users.show', $booking->user) }}">{{ $booking->user->name }}</a>
                                @else — @endif
                            @else
                                {{ $booking->user?->name ?? '—' }}
                            @endcan
                        </dd>
                        <dt>Service</dt>
                        <dd>{{ $booking->service?->name ?? '—' }}</dd>
                        <dt>Carrier</dt>
                        <dd>{{ $booking->carrier?->company_name ?? 'Unassigned' }}</dd>
                        <dt>Driver</dt>
                        <dd>{{ $booking->driver?->name ?? 'Unassigned' }}</dd>
                        <dt>Truck</dt>
                        <dd>{{ $booking->truck?->unit_number ?? '—' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Review</h2></div>
                <div class="card__body">
                    @if ($booking->review)
                        @include('admin.partials.stars', [
                            'value' => $booking->review->rating_overall,
                            'showValue' => true,
                        ])
                        @include('admin.partials.status-badge', ['status' => $booking->review->status])
                        <p class="stack-sm">
                            <a class="btn btn--secondary btn--sm" href="{{ route('admin.reviews.show', $booking->review) }}">
                                Open review
                            </a>
                        </p>
                    @elseif ($booking->canBeReviewed())
                        <p class="muted">Delivered and not yet reviewed — the customer can leave one in the app.</p>
                    @else
                        <p class="muted">No review. One becomes possible once the shipment is delivered.</p>
                    @endif
                </div>
            </section>

            @if ($booking->special_instructions || $booking->cancellation_reason)
                <section class="card">
                    <div class="card__head"><h2 class="card__title">Notes</h2></div>
                    <div class="card__body">
                        @if ($booking->special_instructions)
                            <h3 class="section-head">Special instructions</h3>
                            <p>{{ $booking->special_instructions }}</p>
                        @endif
                        @if ($booking->cancellation_reason)
                            <h3 class="section-head">Cancellation</h3>
                            <p>{{ $booking->cancellation_reason }}</p>
                            <p class="tiny muted">{{ $booking->cancelled_at?->format('j M Y H:i') }}</p>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
