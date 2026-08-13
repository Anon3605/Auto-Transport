<?php

namespace App\Http\Requests\Api;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /quotes/estimate -- the instant, non-binding §7 price. Nothing is
 * persisted, so this is a subset of quoteRequestSchema: the service, the
 * vehicles, and whatever the client knows about the lane.
 *
 * Open to guests, which is why the payload is capped at eight vehicles and the
 * distance is bounded: the endpoint runs two cheap queries and some arithmetic,
 * and both limits keep it that way. Rate limiting is the route's job.
 */
class EstimateRequest extends FormRequest
{
    /** Longer than any road lane in the lower 48 plus a generous detour. */
    private const MAX_DISTANCE_MILES = 20000;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /**
             * Required here even though quoteRequestSchema types service_id as
             * nullable: an estimate IS the service's pricing row applied to a
             * lane, and there is no defensible fallback service to guess at.
             * Scoped to active, undeleted rows so a retired service cannot be
             * priced by id.
             */
            'service_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('services', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],

            /**
             * Accepted from the client because there is no Distance Matrix key in
             * this environment (see QuoteEstimator::stubDistanceMiles). It is a
             * hint, not a price: the estimator still reads base/per-mile/min from
             * the service row, so the worst a forged value does is misprice a
             * number the customer is told is subject to confirmation.
             */
            'distance_miles' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DISTANCE_MILES],

            'vehicles' => ['required', 'array', 'min:1', 'max:8'],
            'vehicles.*.vehicle_type_id' => [
                'nullable',
                'integer',
                Rule::exists('vehicle_types', 'id')->where('is_active', true),
            ],
            'vehicles.*.is_operable' => ['nullable', 'boolean'],

            // Only the coordinates matter; addressSchema's other keys may ride
            // along and are ignored rather than rejected, so the client can post
            // the same address object it uses on the full quote form.
            'pickup' => ['nullable', 'array'],
            'pickup.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff' => ['nullable', 'array'],
            'dropoff.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service_id.required' => 'Choose a transport service.',
            'service_id.exists' => 'That service is not available.',
            'vehicles.required' => 'Add at least one vehicle.',
            'vehicles.min' => 'Add at least one vehicle.',
            'vehicles.max' => 'Please contact us directly for more than eight vehicles.',
        ];
    }

    public function service(): Service
    {
        // The exists rule above already proved the row is there and active.
        return Service::query()->findOrFail((int) $this->input('service_id'));
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicles(): array
    {
        return array_values((array) $this->input('vehicles', []));
    }

    public function distanceMiles(): ?int
    {
        $miles = $this->input('distance_miles');

        return $miles === null ? null : (int) $miles;
    }

    /** @return array<string, float|null> */
    public function lane(): array
    {
        return [
            'pickup_lat' => $this->coordinate('pickup.lat'),
            'pickup_lng' => $this->coordinate('pickup.lng'),
            'dropoff_lat' => $this->coordinate('dropoff.lat'),
            'dropoff_lng' => $this->coordinate('dropoff.lng'),
        ];
    }

    private function coordinate(string $key): ?float
    {
        $value = $this->input($key);

        return $value === null || $value === '' ? null : (float) $value;
    }
}
