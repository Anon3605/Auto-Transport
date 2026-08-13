<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * rating_avg / rating_count are absent on purpose: they are denormalised
     * aggregates rebuilt from approved reviews, never accepted from a request.
     */
    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'short_description',
        'description',
        'icon',
        'hero_image_path',
        'base_price_cents',
        'price_per_mile_cents',
        'min_price_cents',
        'currency',
        'transit_days_min',
        'transit_days_max',
        'is_featured',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'base_price_cents' => 'integer',
            'price_per_mile_cents' => 'integer',
            'min_price_cents' => 'integer',
            'transit_days_min' => 'integer',
            'transit_days_max' => 'integer',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Services have no ulid column; endpoints.ts addresses them as /services/{slug}. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<ServiceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * The only reviews that may feed public listings or the rating aggregate
     * rebuild — see database-design.md §4.7.
     *
     * @return HasMany<Review, $this>
     */
    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', ReviewStatus::Approved);
    }

    /** @return MorphOne<SeoMeta, $this> */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'metable');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Money leaves the model in minor units so the API resource can emit the
     * { cents, currency } shape the client's Money interface expects (§4.4).
     */
    protected function basePrice(): Attribute
    {
        return Attribute::get(fn (): array => $this->money($this->base_price_cents));
    }

    protected function pricePerMile(): Attribute
    {
        return Attribute::get(fn (): array => $this->money($this->price_per_mile_cents));
    }

    protected function minPrice(): Attribute
    {
        return Attribute::get(fn (): array => $this->money($this->min_price_cents));
    }

    /** @return array{cents: int, currency: string} */
    protected function money(mixed $cents): array
    {
        return [
            'cents' => (int) $cents,
            'currency' => $this->currency ?? 'USD',
        ];
    }
}
