<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per car. Never vehicle_1_make / vehicle_2_make (design doc §4.2).
 */
class QuoteRequestVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_request_id',
        'vehicle_type_id',
        'year',
        'make',
        'model',
        'trim',
        'color',
        'vin',
        'is_operable',
        'is_modified',
        'length_in',
        'weight_lb',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_operable' => 'bool',
            'is_modified' => 'bool',
        ];
    }

    /**
     * quote_requests.vehicle_count is a denormalised child count (design doc
     * §4.2) so the admin lead list can render "3 vehicles" without an N+1. The
     * parent cannot maintain it -- nothing fires on the parent when a child is
     * written -- so the child owns the sync.
     */
    protected static function booted(): void
    {
        static::created(fn (self $vehicle) => $vehicle->syncParentCount());
        static::deleted(fn (self $vehicle) => $vehicle->syncParentCount());
    }

    protected function syncParentCount(): void
    {
        // Recount from source instead of incrementing: a sync that fails once
        // self-heals on the next write rather than drifting forever.
        $count = static::where('quote_request_id', $this->quote_request_id)->count();

        QuoteRequest::withTrashed()
            ->whereKey($this->quote_request_id)
            ->update(['vehicle_count' => $count]);
    }

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
