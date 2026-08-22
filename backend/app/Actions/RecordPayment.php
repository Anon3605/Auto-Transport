<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Appends a payment to a booking's ledger and re-derives what it has been paid.
 *
 * Two rules from §4.11 shape this, and both are easy to get wrong in a way that
 * only surfaces during a reconciliation nobody enjoys:
 *
 *  1. The ledger is APPEND-ONLY. Nothing here updates an existing payment row. A
 *     refund is a new row with `type = refund` pointing at the capture it
 *     reverses; corrections are new rows too. Ledgers do not get edited.
 *
 *  2. `bookings.amount_paid_cents` is RECOMPUTED from the rows, never
 *     incremented. Incrementing drifts the moment anything is inserted by
 *     another path — a webhook, a manual fix, a seeder — and the drift is
 *     invisible until someone compares the total against the rows. Recomputing
 *     is self-healing: it is always the sum of what is actually there.
 */
class RecordPayment
{
    /**
     * @param  array<string, mixed>  $data  validated RecordPaymentRequest payload
     *
     * @throws DomainException when the idempotency key has already been used
     */
    public function handle(Booking $booking, User $recordedBy, array $data): Payment
    {
        return DB::transaction(function () use ($booking, $recordedBy, $data): Payment {
            try {
                $payment = $booking->payments()->create([
                    'user_id' => $booking->user_id,
                    'type' => $data['type'],
                    'gateway' => $data['gateway'],
                    'gateway_reference' => $data['gateway_reference'] ?? null,
                    'idempotency_key' => $data['idempotency_key'],
                    'amount_cents' => $data['amount_cents'],
                    'currency' => $booking->currency,

                    // Captured, not pending: a human is asserting the money arrived.
                    // There is no gateway to confirm it later.
                    'status' => 'captured',

                    'card_brand' => $data['card_brand'] ?? null,
                    'card_last4' => $data['card_last4'] ?? null,
                    'paid_at' => $data['paid_at'] ?? now(),

                    // Who asserted it. This is the audit trail for a manual entry.
                    'recorded_by' => $recordedBy->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same form submitted twice. Caught rather than crediting twice.
                throw new DomainException('That payment has already been recorded.');
            }

            $this->syncAmountPaid($booking);

            $booking->recordEvent('payment_recorded', [
                'description' => sprintf(
                    '%s of %s %s recorded by %s%s.',
                    ucfirst($data['type']),
                    $booking->currency,
                    number_format($data['amount_cents'] / 100, 2),
                    $recordedBy->name,
                    isset($data['note']) && $data['note'] !== '' ? ' — '.$data['note'] : '',
                ),

                // The customer sees that their payment landed; that is reassuring
                // and it is their money. The internal note rides along in the
                // description because dispatch reads the same row.
                'is_customer_visible' => true,
            ]);

            /*
             * Paid in full while awaiting payment is the one case where the status
             * move needs no separate judgement: the reason the booking was held is
             * gone. It still goes through transitionTo(), so the state machine and
             * the timeline event are the same ones a manual confirm would produce.
             */
            if ($booking->status === BookingStatus::PendingPayment
                && $booking->balance_due['cents'] === 0) {
                $booking->transitionTo(
                    BookingStatus::Confirmed,
                    'Confirmed automatically: paid in full.'
                );
            }

            return $payment;
        });
    }

    /**
     * Re-derive amount_paid_cents as the signed sum of the ledger.
     *
     * signedAmountCents() returns 0 for anything not counting as paid and a
     * negative for refunds and chargebacks, so this one expression covers
     * captures, failures and reversals without a special case per type.
     */
    private function syncAmountPaid(Booking $booking): void
    {
        $paid = $booking->payments()
            ->get()
            ->sum(fn (Payment $payment): int => $payment->signedAmountCents());

        // Floored at zero: an over-refund is a real situation, but a negative
        // "amount paid" is not a number any screen or invoice can render.
        $booking->forceFill(['amount_paid_cents' => max(0, $paid)])->save();
    }
}
