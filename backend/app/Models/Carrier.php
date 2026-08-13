<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Subcontracted motor carrier. Insurance expiry is a hard dispatch gate:
 * the carriers_status_ins_idx index exists to answer "who is approved and
 * still covered" cheaply.
 */
class Carrier extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * rating_avg / rating_count are denormalised aggregates rebuilt from
     * approved reviews (design doc §4.7); they are never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'mc_number',
        'dot_number',
        'contact_name',
        'email',
        'phone',
        'insurance_provider',
        'insurance_policy_no',
        'insurance_expires_at',
        'cargo_coverage_cents',
        'status',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'insurance_expires_at' => 'date',
            'cargo_coverage_cents' => 'int',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'int',
        ];
    }

    /** @return HasMany<DriverProfile, $this> */
    public function driverProfiles(): HasMany
    {
        return $this->hasMany(DriverProfile::class);
    }

    /** @return HasMany<Truck, $this> */
    public function trucks(): HasMany
    {
        return $this->hasMany(Truck::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Source of truth for rating_avg / rating_count on rebuild.
     *
     * @return HasMany<Review, $this>
     */
    public function approvedReviews(): HasMany
    {
        return $this->reviews()->approved();
    }

    /** @param Builder<$this> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Compliance sweep: approved carriers whose cover lapses inside the window,
     * plus any with no expiry on file at all.
     *
     * @param Builder<$this> $query
     */
    public function scopeInsuranceExpiringWithin(Builder $query, int $days = 30): void
    {
        $query->where('status', self::STATUS_APPROVED)
            ->where(function (Builder $q) use ($days): void {
                $q->whereNull('insurance_expires_at')
                    ->orWhere('insurance_expires_at', '<=', now()->addDays($days));
            });
    }
}
