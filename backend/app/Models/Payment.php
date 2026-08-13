<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only ledger row (design doc §4.11). A refund is a NEW row of type
 * 'refund' pointing back via refunds_payment_id -- never an UPDATE of the
 * capture it reverses.
 */
class Payment extends Model
{
    use HasFactory, HasUlid;

    public const TYPE_DEPOSIT    = 'deposit';
    public const TYPE_BALANCE    = 'balance';
    public const TYPE_FULL       = 'full';
    public const TYPE_REFUND     = 'refund';
    public const TYPE_CHARGEBACK = 'chargeback';

    /**
     * Types that move money back out. amount_cents is unsigned, so direction
     * lives in the type, never in the sign of the column.
     */
    public const OUTBOUND_TYPES = [self::TYPE_REFUND, self::TYPE_CHARGEBACK];

    /**
     * DB defaults restated in PHP so a fresh instance never reports a null status
     * or currency before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PaymentStatus::Pending->value,
        'currency' => 'USD',
    ];

    /** @var list<string> */
    protected $fillable = [
        'booking_id',
        'user_id',
        'type',
        'gateway',
        'gateway_reference',
        'gateway_payload',
        'idempotency_key',
        'amount_cents',
        'currency',
        'status',
        'card_brand',
        'card_last4',
        'paid_at',
        'failure_code',
        'failure_reason',
        'refunds_payment_id',
        'recorded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Integer minor units end to end -- never divide before a sum (§4.4).
            'amount_cents' => 'int',
            'status' => PaymentStatus::class,
            // WARNING: this column will happily swallow the gateway's full card
            // object if you dump a raw response into it. Filter on write --
            // card_brand and card_last4 are the ONLY card data allowed in this
            // database. No PAN, no CVV, no expiry, ever (design doc §4.11).
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Set only on manual/offline entries. @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The capture this row reverses. @return BelongsTo<self, $this> */
    public function refundsPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refunds_payment_id');
    }

    /** Reversals issued against this capture. @return HasMany<self, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refunds_payment_id');
    }

    /** @param Builder<$this> $query */
    public function scopeCaptured(Builder $query): void
    {
        $query->where('status', PaymentStatus::Captured);
    }

    /** @param Builder<$this> $query */
    public function scopeForBooking(Builder $query, int $bookingId): void
    {
        $query->where('booking_id', $bookingId);
    }

    /** Signed contribution of this row to bookings.amount_paid_cents. */
    public function signedAmountCents(): int
    {
        if ($this->status?->countsAsPaid() !== true) {
            return 0;
        }

        return in_array($this->type, self::OUTBOUND_TYPES, true)
            ? -$this->amount_cents
            : $this->amount_cents;
    }

    /**
     * Authoritative net-paid figure for a booking, in cents. bookings.amount_paid_cents
     * is a cached copy of this; recompute here rather than incrementing in place,
     * so a missed webhook self-corrects on the next run.
     *
     * Aggregates in the DB, so it stays correct for a booking with thousands of rows.
     */
    public static function netCapturedCentsForBooking(int $bookingId): int
    {
        $totals = static::query()
            ->forBooking($bookingId)
            ->captured()
            ->groupBy('type')
            ->selectRaw('type, SUM(amount_cents) as total_cents')
            ->pluck('total_cents', 'type');

        $net = 0;

        foreach ($totals as $type => $total) {
            $net += in_array($type, self::OUTBOUND_TYPES, true) ? -(int) $total : (int) $total;
        }

        return $net;
    }
}
