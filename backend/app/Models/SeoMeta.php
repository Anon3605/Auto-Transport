<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One polymorphic row per page/service/location — see database-design.md §4.9.
 */
class SeoMeta extends Model
{
    use HasFactory;

    /** Laravel would pluralise to 'seo_metas'. */
    protected $table = 'seo_meta';

    protected $fillable = [
        'meta_title',
        'meta_description',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image_path',
        'twitter_card',
        'schema_json',
        'sitemap_priority',
        'sitemap_changefreq',
    ];

    protected function casts(): array
    {
        return [
            // JSON-LD: LocalBusiness, Service, AggregateRating.
            'schema_json' => 'array',
            'sitemap_priority' => 'decimal:1',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function metable(): MorphTo
    {
        return $this->morphTo();
    }
}
