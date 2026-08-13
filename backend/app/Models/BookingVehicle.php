<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One car on a shipment, plus its condition report. The damage maps are the
 * evidence in a damage claim: [{x,y,code,note}] plotted over a car diagram at
 * pickup and again at delivery, so the two are directly comparable.
 */
class BookingVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'vehicle_type_id',
        'year',
        'make',
        'model',
        'color',
        'vin',
        'is_operable',
        'pickup_odometer',
        'delivery_odometer',
        'pickup_damage_map',
        'delivery_damage_map',
        'pickup_condition_notes',
        'delivery_condition_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_operable'         => 'bool',
            'pickup_damage_map'   => 'array',
            'delivery_damage_map' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }
}
