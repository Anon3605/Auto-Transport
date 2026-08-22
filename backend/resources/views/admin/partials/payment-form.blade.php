{{--
    Manual payment entry.

    There is no gateway wired, so a human is asserting that the money arrived and
    the row records who asserted it (`recorded_by`).

    NO card number, CVV or expiry field exists here, on purpose (§4.11). Last four
    and brand are the only card details this database keeps, and the surest way to
    keep a full PAN out of it is to give it nowhere to go.

    @param \App\Models\Booking $booking
--}}
@php
    /*
     * A first payment on a booking with a deposit set is usually the deposit;
     * once something is paid, the next one is usually the balance. Only a
     * default — the operator can always override it.
     */
    $suggestedType = (int) $booking->amount_paid['cents'] === 0
        ? ((int) $booking->deposit_cents > 0 ? 'deposit' : 'full')
        : 'balance';

    $outstanding = number_format($booking->balance_due['cents'] / 100, 2, '.', '');
@endphp

<section class="card">
    <div class="card__head">
        <h2 class="card__title">Record a payment</h2>
    </div>

    <div class="card__body">
        <form method="POST" action="{{ route('admin.bookings.payments.store', $booking) }}" class="stack-sm">
            @csrf

            {{-- Minted per render. The column is UNIQUE, so a double-tapped submit
                 or a browser retry inserts once instead of crediting twice. --}}
            <input type="hidden" name="idempotency_key" value="{{ Str::ulid() }}">

            <div class="form-field">
                <label class="form-label" for="amount">
                    Amount ({{ $booking->currency }})
                    <span class="form-required" aria-hidden="true">*</span>
                </label>
                <input class="input num" type="number" name="amount" id="amount"
                       step="0.01" min="0.01" required
                       value="{{ old('amount', $outstanding) }}">
                @error('amount')<p class="form-error">{{ $message }}</p>@enderror
                @error('amount_cents')<p class="form-error">{{ $message }}</p>@enderror
                <p class="form-hint">
                    Pre-filled with the outstanding balance. Entered in
                    {{ $booking->currency }}, stored as integer cents.
                </p>
            </div>

            <div class="form-field">
                <label class="form-label" for="type">
                    Type <span class="form-required" aria-hidden="true">*</span>
                </label>
                <select class="select" name="type" id="type" required>
                    <option value="deposit" @selected(old('type', $suggestedType) === 'deposit')>Deposit</option>
                    <option value="balance" @selected(old('type', $suggestedType) === 'balance')>Balance</option>
                    <option value="full" @selected(old('type', $suggestedType) === 'full')>Full amount</option>
                </select>
                @error('type')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <label class="form-label" for="gateway">
                    Method <span class="form-required" aria-hidden="true">*</span>
                </label>
                <select class="select" name="gateway" id="gateway" required>
                    @foreach (['manual' => 'Manual / other', 'bank_transfer' => 'Bank transfer', 'card_terminal' => 'Card (phone or terminal)', 'cash' => 'Cash'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('gateway', 'bank_transfer') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('gateway')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <label class="form-label" for="gateway_reference">Reference</label>
                <input class="input mono" type="text" name="gateway_reference" id="gateway_reference"
                       maxlength="191" value="{{ old('gateway_reference') }}"
                       placeholder="Bank reference or receipt number">
                @error('gateway_reference')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-grid">
                <div class="form-field">
                    <label class="form-label" for="card_brand">Card brand</label>
                    <input class="input" type="text" name="card_brand" id="card_brand"
                           maxlength="32" value="{{ old('card_brand') }}" placeholder="Visa">
                    @error('card_brand')<p class="form-error">{{ $message }}</p>@enderror
                </div>

                <div class="form-field">
                    <label class="form-label" for="card_last4">Last 4 digits</label>
                    <input class="input mono" type="text" name="card_last4" id="card_last4"
                           inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                           value="{{ old('card_last4') }}" placeholder="4242">
                    @error('card_last4')<p class="form-error">{{ $message }}</p>@enderror
                    <p class="form-hint">Never record the full card number.</p>
                </div>
            </div>

            <div class="form-field">
                <label class="form-label" for="paid_at">Received on</label>
                <input class="input" type="datetime-local" name="paid_at" id="paid_at"
                       value="{{ old('paid_at') }}">
                @error('paid_at')<p class="form-error">{{ $message }}</p>@enderror
                <p class="form-hint">Leave blank to record it as now.</p>
            </div>

            <div class="form-field">
                <label class="form-label" for="note">Note</label>
                <input class="input" type="text" name="note" id="note"
                       maxlength="500" value="{{ old('note') }}">
                @error('note')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--block">Record payment</button>
            </div>

            @if ($booking->status === \App\Enums\BookingStatus::PendingPayment)
                <p class="form-hint">
                    Recording the full balance confirms this booking automatically —
                    the reason it was being held is gone.
                </p>
            @endif
        </form>
    </div>
</section>
