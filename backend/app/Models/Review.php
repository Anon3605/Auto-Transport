<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anchored to exactly one delivered booking (design doc §4.7). service_id,
 * carrier_id and driver_id are copied at write time so the review survives a
 * later reassignment and aggregate queries need no joins.
 */
class Review extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * DB defaults restated in PHP so a just-created instance serializes with a
     * real status instead of null -- the API contract types status as
     * non-nullable, and the resource is built before any refresh().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ReviewStatus::Pending->value,   // fail closed: nothing public until moderated
        'is_verified' => true,
        'is_featured' => false,
        'helpful_count' => 0,
    ];

    /**
     * Deliberately absent:
     *  - is_verified: derived from booking linkage, always true for reviews created
     *    through this flow. It must never become a settable admin field, or the
     *    "verified" badge stops meaning "we have the shipment record" (§4.7).
     *  - status / moderated_by / moderated_at / rejection_reason: written only by
     *    approve() and reject() below, so every transition leaves a moderator trail.
     *  - helpful_count: a cached count of review_votes rows, not user input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'user_id',
        'service_id',
        'carrier_id',
        'driver_id',
        'rating_overall',
        'rating_communication',
        'rating_timeliness',
        'rating_condition',
        'rating_value',
        'title',
        'body',
        'is_featured',
        'admin_reply',
        'admin_replied_at',
        'admin_replied_by',
        'ip_address',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating_overall' => 'int',
            'rating_communication' => 'int',
            'rating_timeliness' => 'int',
            'rating_condition' => 'int',
            'rating_value' => 'int',
            'is_verified' => 'bool',
            'is_featured' => 'bool',
            'moderated_at' => 'datetime',
            'admin_replied_at' => 'datetime',
            'helpful_count' => 'int',
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

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Carrier, $this> */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /** driver_id points at users, not driver_profiles. @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function adminRepliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_replied_by');
    }

    /** @return HasMany<ReviewVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(ReviewVote::class);
    }

    /** Customer-supplied photos, collection 'review_photos'. @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    /** @param Builder<$this> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', ReviewStatus::Approved);
    }

    /** @param Builder<$this> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReviewStatus::Pending);
    }

    /** Homepage testimonial slot -- featured is meaningless unless also approved. @param Builder<$this> $query */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true)->where('status', ReviewStatus::Approved);
    }

    /** @param Builder<$this> $query */
    public function scopeForService(Builder $query, int $serviceId): void
    {
        $query->where('service_id', $serviceId);
    }

    /** The only sanctioned path to a public review. */
    public function approve(User $moderator): void
    {
        $this->status = ReviewStatus::Approved;
        $this->moderated_by = $moderator->id;
        $this->moderated_at = now();
        $this->rejection_reason = null;  // a re-approved review must not keep a stale reason
        $this->save();
    }

    /** Reason is mandatory: the customer gets told why, and moderation has to be auditable. */
    public function reject(User $moderator, string $reason): void
    {
        $this->status = ReviewStatus::Rejected;
        $this->moderated_by = $moderator->id;
        $this->moderated_at = now();
        $this->rejection_reason = $reason;
        $this->save();
    }

    /**
     * Mean of whichever sub-ratings the customer actually filled in. Null when
     * they skipped all four -- averaging nothing as 0 would drag the display down.
     */
    public function averageSubRating(): ?float
    {
        $given = array_filter([
            $this->rating_communication,
            $this->rating_timeliness,
            $this->rating_condition,
            $this->rating_value,
        ], static fn (?int $rating): bool => $rating !== null);

        if ($given === []) {
            return null;
        }

        return round(array_sum($given) / count($given), 2);
    }

    /**
     * PRIVACY: approved reviews render on public marketing pages and in the app,
     * so the author is shown as first name plus last initial ("Sarah M.") and the
     * full name never leaves the database. Truncation happens here, in the model,
     * rather than in each resource -- there is no path that forgets to do it.
     */
    protected function authorName(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = preg_split('/\s+/', trim((string) $this->user?->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($parts === []) {
                return 'Anonymous';   // author deleted or anonymised; the review survives
            }

            $first = array_shift($parts);
            $last = array_pop($parts);   // null for a single-token name

            return $last === null
                ? $first
                : $first.' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
        });
    }
}
