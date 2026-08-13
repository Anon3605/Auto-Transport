<?php

namespace App\Http\Requests\Admin;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Catalog editing. Prices are entered and validated in CENTS, matching the
 * column names the form posts (base_price_cents, ...) -- a dollars field would
 * need a float round-trip on every save, and §4.4 keeps money in minor units
 * end to end.
 *
 * rating_avg / rating_count are absent: they are denormalised aggregates
 * rebuilt from approved reviews (§4.7) and are not in Service::$fillable
 * either, so even a hand-built POST cannot forge a rating.
 */
class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_category_id' => ['nullable', 'integer', Rule::exists('service_categories', 'id')],
            'name' => ['required', 'string', 'min:2', 'max:160'],

            'slug' => [
                'required',
                'string',
                'max:180',
                // The slug is the public URL (/services/{slug}) and the route key,
                // so anything that would need escaping is rejected outright.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('services', 'slug')->ignore($this->target()?->getKey()),
            ],

            'short_description' => ['nullable', 'string', 'max:320'],
            'description' => ['nullable', 'string', 'max:65000'],
            'icon' => ['nullable', 'string', 'max:64'],

            // unsignedBigInteger columns: no negative prices, and min_price is
            // the floor the estimator clamps to, so it may legitimately be 0.
            'base_price_cents' => ['required', 'integer', 'min:0'],
            'price_per_mile_cents' => ['required', 'integer', 'min:0'],
            'min_price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],

            // unsignedSmallInteger, and a window that runs backwards would print
            // "5-2 days" on the public services page.
            'transit_days_min' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'transit_days_max' => ['nullable', 'integer', 'min:0', 'max:65535', 'gte:transit_days_min'],

            'is_featured' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes.',
            'transit_days_max.gte' => 'The longest transit time cannot be shorter than the shortest.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => is_string($slug = $this->input('slug')) ? mb_strtolower(trim($slug)) : $slug,
            'currency' => is_string($currency = $this->input('currency')) ? mb_strtoupper(trim($currency)) : $currency,

            // An unchecked checkbox posts nothing at all, so absence has to mean
            // false here or a service could never be deactivated.
            'is_featured' => $this->boolean('is_featured'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    private function target(): ?Service
    {
        $service = $this->route('service');

        return $service instanceof Service ? $service : null;
    }
}
