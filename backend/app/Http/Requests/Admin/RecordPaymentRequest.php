<?php

namespace App\Http\Requests\Admin;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Records money received against a booking.
 *
 * This is the MANUAL entry path — a bank transfer, a card taken over the phone,
 * cash on collection. There is no gateway integration, so a human is asserting
 * "this money arrived" and the row records who asserted it (`recorded_by`).
 *
 * WHAT THIS FORM DELIBERATELY DOES NOT ACCEPT: card number, CVV or expiry.
 * Only `card_last4` and `card_brand`, which are the two fields the schema keeps
 * (§4.11). A full card number must never reach this database, and the surest way
 * to guarantee that is to give it nowhere to go.
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_bookings') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // The operator types dollars; the ledger stores integer minor units
        // (§4.4). Converting here keeps the float out of everything downstream.
        $amount = $this->input('amount');

        if (is_numeric($amount)) {
            $this->merge(['amount_cents' => (int) round(((float) $amount) * 100)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],

            // Derived in prepareForValidation; validated so a non-numeric amount
            // cannot slip through as 0.
            'amount_cents' => ['required', 'integer', 'min:1'],

            'type' => ['required', Rule::in([
                Payment::TYPE_DEPOSIT,
                Payment::TYPE_BALANCE,
                Payment::TYPE_FULL,
            ])],

            'gateway' => ['required', 'string', 'max:32'],
            'gateway_reference' => ['nullable', 'string', 'max:191'],

            'card_brand' => ['nullable', 'string', 'max:32'],
            'card_last4' => ['nullable', 'digits:4'],

            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:500'],

            /*
             * A nonce minted when the form rendered. The column is UNIQUE, so a
             * double-tapped submit or a browser retry inserts once and the second
             * attempt is caught rather than silently crediting the customer twice.
             * This is the whole reason the column exists (§4.11).
             */
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'How much was received?',
            'amount.min' => 'A payment has to be more than zero.',
            'card_last4.digits' => 'Enter only the last four digits.',
            'paid_at.before_or_equal' => 'A payment cannot be dated in the future.',
        ];
    }
}
