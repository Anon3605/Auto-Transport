<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactMessage extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_READ = 'read';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_SPAM = 'spam';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Public form fields only. ip_address/user_agent/referrer are derived from
     * the request by the controller and must never be read off the payload;
     * status, assignment and reply columns are staff-only and set explicitly.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'ip_address',
        'user_agent',
        'referrer',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
            'spam_score' => 'integer',
        ];
    }

    /** Set when a known customer submits the form; null for anonymous visitors. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    public function scopeStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }
}
