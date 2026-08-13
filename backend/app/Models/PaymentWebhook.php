<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw gateway receipt, stored before it is interpreted. Persisting first means a
 * handler that throws can be replayed from here instead of begging the gateway
 * to resend. (gateway, event_id) is unique, so a redelivery is a no-op.
 */
class PaymentWebhook extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'gateway',
        'event_id',
        'event_type',
        'payload',
        'signature_valid',
        'processed_at',
        'processing_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'bool',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The dead-letter queue: anything still unprocessed either failed or was
     * never picked up. Drive replay and alerting off this scope.
     *
     * @param Builder<$this> $query
     */
    public function scopeUnprocessed(Builder $query): void
    {
        $query->whereNull('processed_at');
    }

    /** Stamp the outcome. A row with an error but a processed_at has been retried and given up on. */
    public function markProcessed(?string $error = null): void
    {
        $this->processed_at = now();
        $this->processing_error = $error;
        $this->save();
    }
}
