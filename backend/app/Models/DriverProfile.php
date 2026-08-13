<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Driver-specific attributes hanging off a user row; identity itself stays on
 * users. Addressed internally only -- the table has no ulid, so no HasUlid.
 */
class DriverProfile extends Model
{
    use HasFactory;

    /**
     * rating_avg / rating_count are rebuilt from approved reviews
     * (design doc §4.7), not written by a form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'carrier_id',
        'license_number',
        'license_state',
        'license_expires_at',
        'cdl_class',
        'is_available',
        'last_lat',
        'last_lng',
        'last_ping_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'int',
            'is_available' => 'bool',
            'last_lat' => 'decimal:7',
            'last_lng' => 'decimal:7',
            'last_ping_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Carrier, $this> */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * Assignments point at users.id, not driver_profiles.id.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'driver_id', 'user_id');
    }

    /** @param Builder<$this> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }
}
