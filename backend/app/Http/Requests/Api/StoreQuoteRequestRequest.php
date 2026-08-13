<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors quoteRequestSchema in mobile/src/types/schemas.ts rule for rule,
 * including both of its .refine() checks:
 *
 *   1. pickup_date_latest >= pickup_date_earliest  -> after_or_equal, below
 *   2. pickup and dropoff are not the same place   -> after(), below
 *
 * Guest-allowed by design (§4.10): user_id stays null for an anonymous visitor
 * and is claimed later, after e-mail verification. Throttling a public write
 * endpoint is the route's job, not this class's.
 *
 * This class also owns the nested-payload -> flat-column mapping. The client
 * posts `pickup: { city, ... }` because that is how addressSchema is shaped;
 * quote_requests stores pickup_city because addresses are flattened onto the
 * intake record (§4.3). One translation, in the layer that knows both shapes.
 */
class StoreQuoteRequestRequest extends FormRequest
{
    /** addresses.location_type and the two *_location_type columns share this list. */
    private const LOCATION_TYPES = ['residential', 'business', 'terminal', 'auction', 'dealer', 'port'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            /**
             * schemas.ts types this nullable-but-present. Absent is treated as
             * null: it carries the same meaning ("no service picked yet") and
             * rejecting a missing key would break every non-app client for no
             * gain. Scoped to active, undeleted services.
             */
            'service_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('services', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],

            'contact_name' => ['required', 'string', 'min:2', 'max:160'],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],

            'pickup' => ['required', 'array'],
            'dropoff' => ['required', 'array'],

            /**
             * A date WINDOW, not a timestamp -- carriers commit to a range. The
             * format is pinned to Y-m-d rather than accepting anything `date`
             * parses: the column is a DATE, and "next tuesday" arriving from a
             * timezone-confused client is how a pickup lands a day out.
             *
             * No after_or_equal:today here on purpose. schemas.ts does not check
             * it, and a rule the client cannot pre-empt turns a valid-looking
             * form into a 422 the user cannot fix without guessing.
             */
            'pickup_date_earliest' => ['required', 'date_format:Y-m-d'],
            'pickup_date_latest' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:pickup_date_earliest'],
            'dates_flexible' => ['nullable', 'boolean'],

            'vehicles' => ['required', 'array', 'min:1', 'max:8'],
            'vehicles.*.vehicle_type_id' => [
                'nullable',
                'integer',
                Rule::exists('vehicle_types', 'id')->where('is_active', true),
            ],
            'vehicles.*.year' => ['nullable', 'integer', 'min:1900', 'max:'.(((int) date('Y')) + 2)],
            'vehicles.*.make' => ['nullable', 'string', 'max:64'],
            'vehicles.*.model' => ['nullable', 'string', 'max:64'],
            'vehicles.*.color' => ['nullable', 'string', 'max:48'],
            /**
             * ISO 3779: 17 characters, no I/O/Q (they read as 1/0/Q). schemas.ts
             * additionally accepts the empty string; the framework's
             * ConvertEmptyStringsToNull has already turned that into null by the
             * time the rules run, which `nullable` covers.
             */
            'vehicles.*.vin' => ['nullable', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/i'],
            'vehicles.*.is_operable' => ['nullable', 'boolean'],
            'vehicles.*.is_modified' => ['nullable', 'boolean'],

            'additional_notes' => ['nullable', 'string', 'max:2000'],
        ], $this->addressRules('pickup'), $this->addressRules('dropoff'));
    }

    /** Wording copied from schemas.ts so both layers say the same thing. */
    public function messages(): array
    {
        return [
            'contact_name.min' => 'Name is required',
            'contact_email.email' => 'Enter a valid email',
            'pickup.city.required' => 'City is required',
            'dropoff.city.required' => 'City is required',
            'pickup_date_earliest.required' => 'Pick a date',
            'pickup_date_earliest.date_format' => 'Pick a date',
            'pickup_date_latest.after_or_equal' => 'End of window must be on or after the start',
            'vehicles.min' => 'Add at least one vehicle',
            'vehicles.required' => 'Add at least one vehicle',
            'vehicles.max' => 'Please contact us directly for more than eight vehicles.',
            'vehicles.*.vin.regex' => 'Invalid VIN',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $pickup = $this->address('pickup');
                $dropoff = $this->address('dropoff');

                if ($pickup['city'] === null || $dropoff['city'] === null) {
                    return;     // the required rule already reported this
                }

                /**
                 * schemas.ts compares city AND postal_code with ===. This
                 * normalises case and spacing first, because "Miami"/"miami" is
                 * the same lane and the server is the real boundary -- a stricter
                 * check here can only reject a shipment that goes nowhere.
                 */
                if ($this->sameLocality($pickup['city'], $dropoff['city'])
                    && $this->sameLocality($pickup['postal_code'], $dropoff['postal_code'])) {
                    $validator->errors()->add('dropoff', 'Pickup and dropoff cannot be the same location');
                }
            },
        ];
    }

    /**
     * The flat quote_requests column set, ready for create(). Estimator output
     * (distance_miles, estimated_price_cents) and request forensics (ip, agent,
     * source, user_id) are the controller's to add -- they are not in the payload
     * and must never be read from it.
     *
     * @return array<string, mixed>
     */
    public function quoteRequestAttributes(): array
    {
        $pickup = $this->address('pickup');
        $dropoff = $this->address('dropoff');

        return [
            'service_id' => $this->integerOrNull('service_id'),
            'contact_name' => trim((string) $this->input('contact_name')),
            'contact_email' => trim((string) $this->input('contact_email')),
            'contact_phone' => $this->input('contact_phone'),

            'pickup_line1' => $pickup['line1'],
            'pickup_city' => $pickup['city'],
            'pickup_state' => $pickup['state'],
            'pickup_postal_code' => $pickup['postal_code'],
            'pickup_country_code' => $pickup['country_code'],
            'pickup_lat' => $pickup['lat'],
            'pickup_lng' => $pickup['lng'],
            'pickup_location_type' => $pickup['location_type'],

            'dropoff_line1' => $dropoff['line1'],
            'dropoff_city' => $dropoff['city'],
            'dropoff_state' => $dropoff['state'],
            'dropoff_postal_code' => $dropoff['postal_code'],
            'dropoff_country_code' => $dropoff['country_code'],
            'dropoff_lat' => $dropoff['lat'],
            'dropoff_lng' => $dropoff['lng'],
            'dropoff_location_type' => $dropoff['location_type'],

            'pickup_date_earliest' => $this->input('pickup_date_earliest'),
            'pickup_date_latest' => $this->input('pickup_date_latest'),
            'dates_flexible' => $this->boolean('dates_flexible'),

            'additional_notes' => $this->input('additional_notes'),
        ];
    }

    /**
     * Child rows for quote_request_vehicles. Insert them one at a time through
     * the relation: the model's created hook is what keeps vehicle_count honest,
     * and a bulk DB::table() insert would bypass it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function vehicleRows(): array
    {
        return array_map(function (array $vehicle): array {
            $vin = $vehicle['vin'] ?? null;

            return [
                'vehicle_type_id' => isset($vehicle['vehicle_type_id']) ? (int) $vehicle['vehicle_type_id'] : null,
                'year' => isset($vehicle['year']) ? (int) $vehicle['year'] : null,
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'color' => $vehicle['color'] ?? null,
                // VINs are case-insensitive on paper and uppercase everywhere in
                // practice; store one form so a lookup by VIN actually matches.
                'vin' => $vin === null || $vin === '' ? null : strtoupper((string) $vin),
                'is_operable' => $this->flag($vehicle, 'is_operable', true),
                'is_modified' => $this->flag($vehicle, 'is_modified', false),
            ];
        }, array_values((array) $this->input('vehicles', [])));
    }

    /**
     * What QuoteEstimator needs to guess the lane length when the client sends no
     * mileage of its own.
     *
     * @return array<string, float|null>
     */
    public function lane(): array
    {
        $pickup = $this->address('pickup');
        $dropoff = $this->address('dropoff');

        return [
            'pickup_lat' => $pickup['lat'],
            'pickup_lng' => $pickup['lng'],
            'dropoff_lat' => $dropoff['lat'],
            'dropoff_lng' => $dropoff['lng'],
        ];
    }

    /**
     * addressSchema, applied under a prefix.
     *
     * @return array<string, mixed>
     */
    private function addressRules(string $prefix): array
    {
        return [
            $prefix.'.line1' => ['nullable', 'string', 'max:255'],
            $prefix.'.city' => ['required', 'string', 'max:120'],
            $prefix.'.state' => ['nullable', 'string', 'max:120'],
            $prefix.'.postal_code' => ['nullable', 'string', 'max:24'],
            $prefix.'.country_code' => ['nullable', 'string', 'size:2'],
            $prefix.'.lat' => ['nullable', 'numeric', 'between:-90,90'],
            $prefix.'.lng' => ['nullable', 'numeric', 'between:-180,180'],
            $prefix.'.location_type' => ['nullable', Rule::in(self::LOCATION_TYPES)],
        ];
    }

    /**
     * One address, with addressSchema's defaults applied. country_code and
     * location_type are NOT NULL columns with DB defaults, and a DB default does
     * not fire for an explicit null -- so the coalesce is what stops a client
     * that sends `country_code: null` from hitting a constraint violation.
     *
     * @return array<string, mixed>
     */
    private function address(string $prefix): array
    {
        return [
            'line1' => $this->input($prefix.'.line1'),
            'city' => $this->input($prefix.'.city'),
            'state' => $this->input($prefix.'.state'),
            'postal_code' => $this->input($prefix.'.postal_code'),
            'country_code' => strtoupper((string) ($this->input($prefix.'.country_code') ?? 'US')),
            'lat' => $this->coordinate($prefix.'.lat'),
            'lng' => $this->coordinate($prefix.'.lng'),
            'location_type' => $this->input($prefix.'.location_type') ?? 'residential',
        ];
    }

    private function coordinate(string $key): ?float
    {
        $value = $this->input($key);

        return $value === null || $value === '' ? null : (float) $value;
    }

    private function integerOrNull(string $key): ?int
    {
        $value = $this->input($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param array<string, mixed> $vehicle */
    private function flag(array $vehicle, string $key, bool $default): bool
    {
        $value = $vehicle[$key] ?? null;

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function sameLocality(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
}
