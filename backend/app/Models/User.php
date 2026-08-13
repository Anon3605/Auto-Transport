<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * One users table for customers, drivers and staff. Role rows -- not a `type`
 * column -- decide what a user may do, so a dispatcher can also be a driver
 * without a second identity. Driver-only attributes live in driver_profiles.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlid, LogsActivity, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_normalized',
        'avatar_path',
        'locale',
        'timezone',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The activity log ends up in front of support staff and, on request, the
     * data subject -- so credentials and 2FA material are excluded by
     * enumeration rather than by filtering after the fact.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'email',
                'phone',
                'avatar_path',
                'locale',
                'timezone',
                'status',
                'email_verified_at',
                'phone_verified_at',
                'locked_until',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // --- Relationships ---------------------------------------------------

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    /** Leads this staff member owns in the admin queue. */
    public function assignedQuoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class, 'assigned_to');
    }

    /** Shipments this user is driving, as opposed to the ones they booked. */
    public function driverBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'driver_id');
    }

    // --- Accessors -------------------------------------------------------

    /**
     * The RN client has no page origin to resolve against, so the local disk's
     * root-relative "/storage/..." is promoted to an absolute URL. url() leaves
     * the already-absolute form an s3 disk returns untouched.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path
            ? url(Storage::disk()->url($this->avatar_path))
            : null);
    }

    /**
     * Keeps the pair in lockstep: `phone` is whatever the human typed, and no
     * write path can leave `phone_normalized` stale behind it.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value): array => [
            'phone' => $value,
            'phone_normalized' => static::normalizePhone($value),
        ]);
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * Design doc §4.12: the plaintext column is for display and dialling, this
     * derivative is the one we index and match on. Digits plus an optional
     * leading + -- nothing here guesses a country code it was not given.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        return (str_starts_with($value, '+') ? '+' : '').$digits;
    }

    public function isStaff(): bool
    {
        return $this->getRoleNames()
            ->contains(fn (string $name): bool => UserRole::tryFrom($name)?->isStaff() === true);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
