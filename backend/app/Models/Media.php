<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory, HasUlid;

    /** Laravel would pluralise to 'medias'. */
    protected $table = 'media';

    protected $fillable = [
        'collection',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'width',
        'height',
        'alt_text',
        'captured_lat',
        'captured_lng',
        'captured_at',
        'is_public',
        'sort_order',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            // EXIF provenance for pickup/delivery photos.
            'captured_lat' => 'decimal:7',
            'captured_lng' => 'decimal:7',
            'captured_at' => 'datetime',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Owner: a booking, review, service, ... */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Resolved per row because disk varies (local in dev, s3 in production). */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk($this->disk)->url($this->path));
    }

    public function scopeCollection(Builder $query, string $collection): void
    {
        $query->where('collection', $collection);
    }
}
