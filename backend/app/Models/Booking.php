<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\HasUlid;
use App\Support\Reference;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * An accepted quote, snapshotted. The addresses and prices here are flat copies
 * on purpose (design doc §4.3) -- editing a saved address next year must not
 * rewrite what last year's bill of lading says.
 */
class Booking extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'quote_id',
        'quote_request_id',
        'user_id',
        'service_id',
        'status',
        'carrier_id',
        'driver_id',
        'truck_id',
        'dispatched_at',
        'pickup_contact_name',
        'pickup_contact_phone',
        'pickup_line1',
        'pickup_line2',
        'pickup_city',
        'pickup_state',
        'pickup_postal_code',
        'pickup_country_code',
        'pickup_lat',
        'pickup_lng',
        'dropoff_contact_name',
        'dropoff_contact_phone',
        'dropoff_line1',
        'dropoff_line2',
        'dropoff_city',
        'dropoff_state',
        'dropoff_postal_code',
        'dropoff_country_code',
        'dropoff_lat',
        'dropoff_lng',
        'scheduled_pickup_date',
        'scheduled_delivery_date',
        'actual_pickup_at',
        'actual_delivery_at',
        'distance_miles',
        'total_price_cents',
        'deposit_cents',
        'amount_paid_cents',
        'currency',
        'special_instructions',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'review_requested_at',
    ];

    /**
     * DB defaults are not hydrated into the inserting model, and transitionTo()
     * dereferences $this->status -- a null there would fatal on the first move.
     */
    protected $attributes = [
        'status'            => BookingStatus::PendingPayment->value,
        'currency'          => 'USD',
        'amount_paid_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status'                  => BookingStatus::class,
            'dispatched_at'           => 'datetime',
            'scheduled_pickup_date'   => 'date',
            'scheduled_delivery_date' => 'date',
            'actual_pickup_at'        => 'datetime',
            'actual_delivery_at'      => 'datetime',
            'cancelled_at'            => 'datetime',
            'review_requested_at'     => 'datetime',
            'pickup_lat'              => 'decimal:7',
            'pickup_lng'              => 'decimal:7',
            'dropoff_lat'             => 'decimal:7',
            'dropoff_lng'             => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        // booking_number is NOT NULL UNIQUE with no default; generate it here so
        // no creation path can produce a booking a customer cannot quote back.
        static::creating(function (self $booking): void {
            if (empty($booking->booking_number)) {
                $booking->booking_number = Reference::forBooking();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(BookingVehicle::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class);
    }

    /** UNIQUE booking_id on reviews -- one review per shipment (§4.7). */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::Delivered);
    }

    /** Backs the can_review flag in the Booking resource shape (api.ts). */
    public function canBeReviewed(): bool
    {
        return $this->status->allowsReview() && $this->review()->doesntExist();
    }

    /**
     * Appends to the timeline. occurred_at is separate from created_at because a
     * driver's offline location ping is recorded when it happened, not when the
     * phone finally reached the API.
     */
    public function recordEvent(string $type, array $attrs = []): BookingEvent
    {
        return $this->events()->create(array_merge([
            'event_type'  => $type,
            'occurred_at' => now(),
            'created_by'  => auth()->id(),
        ], $attrs));
    }

    /**
     * The only sanctioned way to move a booking's status.
     *
     * Design doc §4.8 is explicit: never UPDATE a status without also INSERTing
     * an event. The transaction is what keeps that promise -- a timeline with a
     * hole in it is worthless as an audit trail, and the hole would only be
     * noticed during the dispute it was meant to settle.
     *
     * @throws DomainException on a transition the state machine forbids
     */
    public function transitionTo(BookingStatus $next, ?string $description = null): void
    {
        $from = $this->status;

        if (! in_array($next, $from->allowedNext(), true)) {
            throw new DomainException(sprintf(
                'Booking %s cannot move from %s to %s.',
                $this->booking_number,
                $from->value,
                $next->value,
            ));
        }

        DB::transaction(function () use ($from, $next, $description): void {
            $this->status = $next;
            $this->save();

            $this->recordEvent('status_change', [
                'from_status'  => $from->value,
                'to_status'    => $next->value,
                'description'  => $description ?? sprintf('Status changed to %s.', $next->label()),
            ]);
        });
    }

    /** Money shape expected by the Money interface in mobile/src/types/api.ts. */
    protected function totalPrice(): Attribute
    {
        return Attribute::get(fn (): array => $this->money($this->total_price_cents));
    }

    protected function deposit(): Attribute
    {
        return Attribute::get(fn (): array => $this->money($this->deposit_cents));
    }

    protected function amountPaid(): Attribute
    {
        return Attribute::get(fn (): array => $this->money($this->amount_paid_cents));
    }

    /**
     * Floored at zero: an over-refund or a duplicated capture must never render
     * as a negative "amount owed" in the app.
     */
    protected function balanceDue(): Attribute
    {
        return Attribute::get(fn (): array => $this->money(
            max(0, (int) $this->total_price_cents - (int) $this->amount_paid_cents)
        ));
    }

    private function money(int|string|null $cents): array
    {
        return ['cents' => (int) $cents, 'currency' => $this->currency];
    }
}
