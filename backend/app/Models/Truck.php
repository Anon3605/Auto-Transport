<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fleet asset. unit_number is unique per carrier, not globally -- two carriers
 * may both run a "07".
 */
class Truck extends Model
{
    use HasFactory;

    public const TRAILER_OPEN     = 'open';
    public const TRAILER_ENCLOSED = 'enclosed';
    public const TRAILER_FLATBED  = 'flatbed';

    /** @var list<string> */
    protected $fillable = [
        'carrier_id',
        'unit_number',
        'plate',
        'trailer_type',
        'capacity_vehicles',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_vehicles' => 'int',
            'is_active' => 'bool',
        ];
    }

    /** @return BelongsTo<Carrier, $this> */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
