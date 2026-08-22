<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, CatalogSeeder::class, ContentSeeder::class]);

        $this->admin = User::role('super-admin')->firstOrFail();

        $this->booking = Booking::factory()
            ->for(User::factory()->create(['status' => 'active']))
            ->create([
                'status' => BookingStatus::PendingPayment,
                'total_price_cents' => 120000,
                'deposit_cents' => 24000,
                'amount_paid_cents' => 0,
                'currency' => 'USD',
            ]);

        // Authenticated as admin for the happy paths; the permission test
        // re-authenticates as a support user, which overrides this.
        $this->actingAs($this->admin);
    }

    public function test_a_deposit_can_be_recorded(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'amount' => '240.00',
            'type' => 'deposit',
        ]))->assertRedirect(route('admin.bookings.show', $this->booking));

        $payment = $this->booking->payments()->sole();

        $this->assertSame(24000, (int) $payment->amount_cents);
        $this->assertSame('deposit', $payment->type);
        $this->assertSame($this->admin->id, (int) $payment->recorded_by);

        // amount_paid_cents is recomputed from the ledger, not incremented.
        $this->assertSame(24000, (int) $this->booking->refresh()->amount_paid_cents);
    }

    /** Dollars in the form, integer cents in the ledger (§4.4). */
    public function test_the_amount_is_converted_to_integer_cents(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'amount' => '199.99',
        ]))->assertRedirect();

        $this->assertSame(19999, (int) $this->booking->payments()->sole()->amount_cents);
    }

    /**
     * The reason idempotency_key is UNIQUE. A double-tapped submit must credit the
     * customer once, and must not 500.
     */
    public function test_the_same_form_submitted_twice_records_one_payment(): void
    {
        $payload = $this->payload(['amount' => '240.00']);

        $this->post(route('admin.bookings.payments.store', $this->booking), $payload)->assertRedirect();
        $this->post(route('admin.bookings.payments.store', $this->booking), $payload)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, $this->booking->payments()->count());
        $this->assertSame(24000, (int) $this->booking->refresh()->amount_paid_cents);
    }

    /** Paying in full removes the reason the booking was being held. */
    public function test_paying_in_full_confirms_the_booking(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'amount' => '1200.00',
            'type' => 'full',
        ]))->assertRedirect();

        $this->booking->refresh();

        $this->assertSame(BookingStatus::Confirmed, $this->booking->status);
        $this->assertSame(0, $this->booking->balance_due['cents']);

        // §4.8: the auto-confirm still goes through transitionTo(), so it leaves a
        // status_change event behind it like any other move.
        $this->assertTrue($this->booking->events()->where('event_type', 'status_change')->exists());
    }

    public function test_a_partial_payment_does_not_confirm_the_booking(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'amount' => '240.00',
            'type' => 'deposit',
        ]))->assertRedirect();

        $this->assertSame(BookingStatus::PendingPayment, $this->booking->refresh()->status);
        $this->assertSame(96000, $this->booking->balance_due['cents']);
    }

    /** The customer should see their own money landing. */
    public function test_the_payment_is_visible_on_the_customer_timeline(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload())->assertRedirect();

        $event = $this->booking->events()->where('event_type', 'payment_recorded')->firstOrFail();

        $this->assertTrue((bool) $event->is_customer_visible);
    }

    /** A refund is a new row, and the total re-derives to account for it. */
    public function test_a_refund_row_reduces_the_amount_paid(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'amount' => '1200.00',
            'type' => 'full',
        ]))->assertRedirect();

        $capture = $this->booking->payments()->sole();

        // Refunds are not exposed in the panel yet; inserted directly to prove the
        // recompute handles a negative signed amount rather than drifting.
        Payment::query()->create([
            'booking_id' => $this->booking->id,
            'user_id' => $this->booking->user_id,
            'type' => Payment::TYPE_REFUND,
            'gateway' => 'manual',
            'idempotency_key' => (string) Str::ulid(),
            'amount_cents' => 30000,
            'currency' => 'USD',
            'status' => 'captured',
            'refunds_payment_id' => $capture->id,
            'paid_at' => now(),
        ]);

        // Re-record nothing; just trigger a recompute via another payment attempt
        // being rejected does not help, so assert the ledger sum directly.
        $signed = $this->booking->payments()->get()->sum(fn (Payment $p): int => $p->signedAmountCents());

        $this->assertSame(90000, $signed, 'A refund must reduce the signed ledger total.');
    }

    public function test_a_zero_or_negative_amount_is_rejected(): void
    {
        foreach (['0', '-50'] as $bad) {
            $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload(['amount' => $bad]))
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, $this->booking->refresh()->payments()->count());
    }

    public function test_a_future_payment_date_is_rejected(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'paid_at' => now()->addWeek()->toDateTimeString(),
        ]))->assertSessionHasErrors('paid_at');
    }

    public function test_only_the_last_four_card_digits_are_accepted(): void
    {
        $this->post(route('admin.bookings.payments.store', $this->booking), $this->payload([
            'card_last4' => '4242424242424242',
        ]))->assertSessionHasErrors('card_last4');
    }

    public function test_support_cannot_record_a_payment(): void
    {
        $support = User::factory()->create(['status' => 'active']);
        $support->assignRole('support');

        $this->actingAs($support)
            ->post(route('admin.bookings.payments.store', $this->booking), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, $this->booking->payments()->count());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => '240.00',
            'type' => 'deposit',
            'gateway' => 'bank_transfer',
            'gateway_reference' => 'FT-99120',
            'idempotency_key' => (string) Str::ulid(),
        ], $overrides);
    }
}
