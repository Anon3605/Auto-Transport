<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Was this review helpful?" -- one vote per user per review, enforced by a
 * unique index. reviews.helpful_count is the cached tally of these rows.
 */
class ReviewVote extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'review_id',
        'user_id',
        'is_helpful',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_helpful' => 'bool',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
