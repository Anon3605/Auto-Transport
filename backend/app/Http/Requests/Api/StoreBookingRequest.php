<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The in-app booking form. Mirrors quoteRequestSchema in
 * mobile/src/types/schemas.ts minus the contact block -- the caller is
 * authenticated, so name and email come from the account rather than from a
 * field an attacker could set to someone else's address.
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Resolved on the slug, like every other service reference.
            'service_slug' => [
                'required',
                'string',
                Rule::exists('services', 'slug')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],

            /*
             * Required, unlike on a quote request. A quote can be priced from a
             * city pair, but a booking is a driver turning up at a door --
             * bookings.pickup_line1 is NOT NULL for that reason, and validating
             * it here turns a 500 from the database into a 422 the form can show.
             */
            'pickup.line1' => ['required', 'string', 'max:255'],
            'pickup.city' => ['required', 'string', 'max:120'],
            'pickup.state' => ['nullable', 'string', 'max:120'],
            'pickup.postal_code' => ['nullable', 'string', 'max:24'],
            'pickup.country_code' => ['nullable', 'string', 'size:2'],
            'pickup.location_type' => ['nullable', Rule::in(['residential', 'business', 'terminal', 'auction', 'dealer', 'port'])],
            'pickup.contact_name' => ['nullable', 'string', 'max:160'],
            'pickup.contact_phone' => ['nullable', 'string', 'max:32'],
            'pickup.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['nullable', 'numeric', 'between:-180,180'],

            'dropoff.line1' => ['required', 'string', 'max:255'],
            'dropoff.city' => ['required', 'string', 'max:120'],
            'dropoff.state' => ['nullable', 'string', 'max:120'],
            'dropoff.postal_code' => ['nullable', 'string', 'max:24'],
            'dropoff.country_code' => ['nullable', 'string', 'size:2'],
            'dropoff.location_type' => ['nullable', Rule::in(['residential', 'business', 'terminal', 'auction', 'dealer', 'port'])],
            'dropoff.contact_name' => ['nullable', 'string', 'max:160'],
            'dropoff.contact_phone' => ['nullable', 'string', 'max:32'],
            'dropoff.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['nullable', 'numeric', 'between:-180,180'],

            // after_or_equal:today, not just a date: a pickup in the past is
            // always a mistake, and it silently breaks the dispatch board's
            // "confirmed jobs by date" ordering.
            'pickup_date_earliest' => ['required', 'date', 'after_or_equal:today'],
            'pickup_date_latest' => ['nullable', 'date', 'after_or_equal:pickup_date_earliest'],
            'dates_flexible' => ['boolean'],

            // Capped at 8, matching schemas.ts. A trailer holds 8-10 cars, so a
            // larger consignment is a commercial conversation, not a form.
            'vehicles' => ['required', 'array', 'min:1', 'max:8'],
            'vehicles.*.vehicle_type_id' => ['nullable', 'integer', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'vehicles.*.year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 2)],
            'vehicles.*.make' => ['nullable', 'string', 'max:64'],
            'vehicles.*.model' => ['nullable', 'string', 'max:64'],
            'vehicles.*.color' => ['nullable', 'string', 'max:48'],
            // ISO 3779 excludes I, O and Q so they cannot be confused with 1 and 0.
            'vehicles.*.vin' => ['nullable', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/i'],
            'vehicles.*.is_operable' => ['boolean'],
            'vehicles.*.is_modified' => ['boolean'],

            'additional_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service_slug.exists' => 'That service is not available.',
            'pickup.line1.required' => 'We need a street address to collect from.',
            'pickup.city.required' => 'Which town or city are we collecting from?',
            'dropoff.line1.required' => 'We need a street address to deliver to.',
            'dropoff.city.required' => 'Which town or city are we delivering to?',
            'pickup_date_earliest.after_or_equal' => 'Pick a collection date that has not already passed.',
            'vehicles.required' => 'Add at least one vehicle.',
            'vehicles.*.vin.regex' => 'A VIN is 17 characters and never contains I, O or Q.',
        ];
    }
}
