<?php

namespace App\Services;

use App\Models\Service;
use App\Models\VehicleType;

/**
 * The instant website quote from design doc §7:
 *
 *   price = max(min_price_cents, base_price_cents + miles × price_per_mile_cents)
 *           × Σ(vehicle_type.price_multiplier)
 *           × (all operable ? 1.0 : 1.35)
 *
 * This is explicitly NOT a quote. It is stored on the lead, shown with a
 * "subject to confirmation" caveat, and superseded by a human-issued `quotes`
 * row (§4.1).
 */
class QuoteEstimator
{
    /** An inoperable car needs a winch and a dedicated slot; §7 prices that at +35%. */
    private const INOPERABLE_MULTIPLIER = 1.35;

    /** Unknown-lane fallback, roughly a US coast-to-coast half-run. */
    public const FALLBACK_DISTANCE_MILES = 1000;

    /**
     * @param  array<int, array<string, mixed>>  $vehicles  rows carrying vehicle_type_id + is_operable
     * @param  array<string, float|string|null>  $lane      pickup_lat/pickup_lng/dropoff_lat/dropoff_lng
     * @return array{cents: int, currency: string, distance_miles: int, multiplier: float, inoperable: bool}
     */
    public function estimate(Service $service, array $vehicles, ?int $distanceMiles = null, array $lane = []): array
    {
        $miles = $distanceMiles ?? $this->stubDistanceMiles($lane);
        $multiplier = $this->multiplierSum($vehicles);
        $inoperable = $this->hasInoperableVehicle($vehicles);

        // Integer cents throughout: the mileage leg is exact, and max() picks the
        // floor price before any multiplier can dilute it.
        $lineCents = max(
            (int) $service->min_price_cents,
            (int) $service->base_price_cents + ($miles * (int) $service->price_per_mile_cents),
        );

        return [
            'cents' => intval(round(
                $lineCents * $multiplier * ($inoperable ? self::INOPERABLE_MULTIPLIER : 1.0)
            )),
            'currency' => $service->currency ?? 'USD',
            'distance_miles' => $miles,
            'multiplier' => $multiplier,
            'inoperable' => $inoperable,
        ];
    }

    /**
     * Σ of the referenced vehicle types' multipliers. Resolved in one query — the
     * estimator runs on the public quote form, and a per-vehicle lookup there is
     * an N+1 on the hottest anonymous endpoint. A vehicle with no type selected
     * counts as 1.0 rather than zeroing the whole estimate.
     *
     * @param  array<int, array<string, mixed>>  $vehicles
     */
    private function multiplierSum(array $vehicles): float
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $vehicle): ?int => isset($vehicle['vehicle_type_id'])
                ? (int) $vehicle['vehicle_type_id']
                : null,
            $vehicles,
        )));

        $multipliers = $ids === []
            ? []
            : VehicleType::query()->whereKey($ids)->pluck('price_multiplier', 'id')->all();

        $sum = 0.0;

        foreach ($vehicles as $vehicle) {
            $id = $vehicle['vehicle_type_id'] ?? null;
            // decimal:3 casts read back as strings ('1.250'), hence the float cast.
            $sum += $id !== null && isset($multipliers[$id]) ? (float) $multipliers[$id] : 1.0;
        }

        return $vehicles === [] ? 1.0 : $sum;
    }

    /** @param array<int, array<string, mixed>> $vehicles */
    private function hasInoperableVehicle(array $vehicles): bool
    {
        foreach ($vehicles as $vehicle) {
            // Absent means operable, matching the column default and vehicleSchema.
            if (array_key_exists('is_operable', $vehicle) && ! filter_var($vehicle['is_operable'], FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * STUB. There is no Google Distance Matrix key in this environment, so this
     * stands in for the routing call §7 describes: great-circle miles when both
     * endpoints are geocoded, a flat national average when they are not. It is
     * deliberately the only place that guesses, so swapping in the real API (and
     * the postal-code → miles cache §7 asks for) is a one-method change.
     *
     * Great-circle is shorter than road distance; no fudge factor is applied here
     * because a wrong-but-explainable number beats a wrong-and-tuned one.
     *
     * @param  array<string, float|string|null>  $lane
     */
    private function stubDistanceMiles(array $lane): int
    {
        $pickupLat = $lane['pickup_lat'] ?? null;
        $pickupLng = $lane['pickup_lng'] ?? null;
        $dropoffLat = $lane['dropoff_lat'] ?? null;
        $dropoffLng = $lane['dropoff_lng'] ?? null;

        if ($pickupLat === null || $pickupLng === null || $dropoffLat === null || $dropoffLng === null) {
            return self::FALLBACK_DISTANCE_MILES;
        }

        $earthRadiusMiles = 3958.8;

        $lat1 = deg2rad((float) $pickupLat);
        $lat2 = deg2rad((float) $dropoffLat);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad((float) $dropoffLng) - deg2rad((float) $pickupLng);

        $h = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return (int) round($earthRadiusMiles * 2 * asin(min(1.0, sqrt($h))));
    }
}
