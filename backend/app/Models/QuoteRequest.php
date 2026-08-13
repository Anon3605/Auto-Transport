<?php

namespace App\Models;

use App\Enums\QuoteRequestStatus;
use App\Models\Concerns\HasUlid;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What the CUSTOMER asked for -- the intake record. Treat it as immutable
 * history: a new price is a new Quote row, never an edit here (design doc §4.1).
 */
class QuoteRequest extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'user_id',
        'service_id',
        'status',
        'contact_name',
        'contact_email',
        'contact_phone',
        'pickup_line1',
        'pickup_city',
        'pickup_state',
        'pickup_postal_code',
        'pickup_country_code',
        'pickup_lat',
        'pickup_lng',
        'pickup_location_type',
        'dropoff_line1',
        'dropoff_city',
        'dropoff_state',
        'dropoff_postal_code',
        'dropoff_country_code',
        'dropoff_lat',
        'dropoff_lng',
        'dropoff_location_type',
        'pickup_date_earliest',
        'pickup_date_latest',
        'dates_flexible',
        'vehicle_count',
        'distance_miles',
        'estimated_price_cents',
        'currency',
        'additional_notes',
        'source',
        'utm',
        'ip_address',
        'user_agent',
        'spam_score',
        'assigned_to',
        'expires_at',
    ];

    /**
     * A column default lives in the database and is NOT hydrated back into the
     * model that just inserted the row, so an intake endpoint serialising the
     * fresh record would read a null status and a null currency. Mirror the two
     * the API contract depends on.
     */
    protected $attributes = [
        'status'        => QuoteRequestStatus::New->value,
        'currency'      => 'USD',
        'vehicle_count' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status'               => QuoteRequestStatus::class,
            'utm'                  => 'array',
            'dates_flexible'       => 'bool',
            'pickup_date_earliest' => 'date',
            'pickup_date_latest'   => 'date',
            'expires_at'           => 'datetime',
            'pickup_lat'           => 'decimal:7',
            'pickup_lng'           => 'decimal:7',
            'dropoff_lat'          => 'decimal:7',
            'dropoff_lng'          => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        // reference is NOT NULL UNIQUE with no default, so fill it here rather
        // than trusting every intake path to remember.
        static::creating(function (self $request): void {
            if (empty($request->reference)) {
                $request->reference = Reference::forQuoteRequest();
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(QuoteRequestVehicle::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** Highest version wins -- re-quoting supersedes, it does not overwrite. */
    public function latestQuote(): HasOne
    {
        return $this->hasOne(Quote::class)->latestOfMany('version');
    }

    /**
     * Attaches a verified user's guest history to their account.
     *
     * The caller MUST NOT invoke this until email_verified_at is set. Claiming
     * on registration alone lets anyone type a stranger's address and inherit
     * their quote history -- addresses, phone numbers, vehicle VINs. Design doc
     * §4.10 calls the verification gate the load-bearing part of the nullable
     * user_id, and this is the sharpest edge in the schema.
     */
    public static function claimGuestRequestsFor(User $user): int
    {
        return static::whereNull('user_id')
            ->where('contact_email', $user->email)
            ->update(['user_id' => $user->id]);
    }

    /** Money shape expected by the Money interface in mobile/src/types/api.ts. */
    protected function estimatedPrice(): Attribute
    {
        return Attribute::get(fn (): ?array => $this->estimated_price_cents === null ? null : [
            'cents'    => (int) $this->estimated_price_cents,
            'currency' => $this->currency,
        ]);
    }
}
