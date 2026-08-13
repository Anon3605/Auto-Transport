<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'lat',
        'lng',
        'phone',
        'email',
        'hours',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // {"mon":["08:00","18:00"], ...} — also feeds LocalBusiness JSON-LD.
            'hours' => 'array',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return MorphOne<SeoMeta, $this> */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'metable');
    }

    /** The single location embedded on the Contact page map. */
    public function scopePrimary(Builder $query): void
    {
        $query->where('is_primary', true);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
