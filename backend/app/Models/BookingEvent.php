<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only timeline (design doc §4.8). Rows are INSERTed and never UPDATEd or
 * corrected in place: this table is simultaneously the tracking feed the RN app
 * renders and the audit trail for "who marked this delivered and when", and an
 * audit trail you can edit answers nothing. A wrong event is superseded by a
 * later one, so the mistake and its correction are both on the record.
 *
 * Write through Booking::recordEvent() / Booking::transitionTo(), not directly.
 */
class BookingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'event_type',
        'from_status',
        'to_status',
        'description',
        'lat',
        'lng',
        'is_customer_visible',
        'created_by',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta'                => 'array',
            'occurred_at'         => 'datetime',
            'lat'                 => 'decimal:7',
            'lng'                 => 'decimal:7',
            'is_customer_visible' => 'bool',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The RN app filters on this; the admin panel deliberately does not. */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_customer_visible', true);
    }

    /** occurred_at, not created_at -- offline pings arrive out of order. */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('occurred_at')->orderBy('id');
    }
}
