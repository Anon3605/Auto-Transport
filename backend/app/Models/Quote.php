<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\HasUlid;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What the COMPANY offered back. Re-quoting inserts version 2 and marks the old
 * row superseded, so "what did we actually quote on 3 March" stays answerable.
 */
class Quote extends Model
{
    use HasFactory, HasUlid;

    protected $fillable = [
        'quote_request_id',
        'issued_by',
        'version',
        'total_price_cents',
        'deposit_cents',
        'carrier_pay_cents',
        'broker_fee_cents',
        'currency',
        'status',
        'valid_until',
        'terms',
        'internal_notes',
        'sent_at',
        'viewed_at',
        'responded_at',
        'decline_reason',
    ];

    /** DB defaults are not hydrated into the inserting model -- see QuoteRequest. */
    protected $attributes = [
        'status'   => QuoteStatus::Draft->value,
        'currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'status'       => QuoteStatus::class,
            'valid_until'  => 'datetime',
            'sent_at'      => 'datetime',
            'viewed_at'    => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $quote): void {
            if (empty($quote->reference)) {
                $quote->reference = Reference::forQuote();
            }
        });
    }

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    /**
     * Backs the expiry sweeper. Rides the (status, valid_until) index; a NULL
     * valid_until means "no deadline" and is excluded by the comparison itself.
     */
    public function scopeExpirable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Viewed])
            ->where('valid_until', '<', now());
    }

    protected function totalPrice(): Attribute
    {
        return Attribute::get(fn (): array => [
            'cents'    => (int) $this->total_price_cents,
            'currency' => $this->currency,
        ]);
    }

    protected function deposit(): Attribute
    {
        return Attribute::get(fn (): array => [
            'cents'    => (int) $this->deposit_cents,
            'currency' => $this->currency,
        ]);
    }
}
