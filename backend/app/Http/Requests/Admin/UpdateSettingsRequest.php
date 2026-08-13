<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The settings form posts settings[<id>] => value, keyed by the row's primary
 * key rather than by "group.key". A key may contain a dot (map.center.lat), and
 * a dot inside a validation attribute name means "nested array" -- keying on
 * the id sidesteps that entirely and lets one form span several groups.
 *
 * Two conventions the Blade form must honour, because absence is ambiguous:
 *   - a bool row ships a hidden 0 immediately before its checkbox, so an
 *     unchecked box arrives as 0 instead of vanishing;
 *   - an encrypted row may be left blank, which means "keep the stored secret".
 */
class UpdateSettingsRequest extends FormRequest
{
    /** @var Collection<int, Setting>|null */
    private ?Collection $rows = null;

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
            'settings' => ['required', 'array'],

            // The `value` column is TEXT for every type; `type` decides how it
            // is read back, so shape is checked per row in after().
            'settings.*' => ['nullable', 'string', 'max:65000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->submitted() as $id => $value) {
                    $setting = $this->rows()->get($id);

                    if ($setting === null) {
                        // Someone else deleted the row, or the id was invented.
                        $validator->errors()->add('settings', 'That form is out of date -- reload and try again.');

                        continue;
                    }

                    $this->validateShape($validator, $setting, $id, $value);
                }
            },
        ];
    }

    /**
     * Submitted rows keyed by settings.id, with non-numeric keys dropped.
     *
     * @return array<int, string|null>
     */
    public function submitted(): array
    {
        $pairs = [];

        foreach ((array) $this->input('settings', []) as $id => $value) {
            if (! is_numeric($id) || is_array($value)) {
                continue;
            }

            $pairs[(int) $id] = $value === null ? null : (string) $value;
        }

        return $pairs;
    }

    /**
     * The rows being edited, fetched once and shared with the controller so a
     * value can never be validated against one row's type and written with
     * another's.
     *
     * @return Collection<int, Setting>
     */
    public function rows(): Collection
    {
        return $this->rows ??= Setting::query()
            ->whereKey(array_keys($this->submitted()))
            ->get()
            ->keyBy('id');
    }

    private function validateShape(Validator $validator, Setting $setting, int $id, ?string $value): void
    {
        $attribute = "settings.{$id}";
        $label = $setting->label ?: "{$setting->group}.{$setting->key}";

        if ($value === null || $value === '') {
            return;   // blank clears a nullable value, or keeps an encrypted one
        }

        match ($setting->type) {
            'int' => $this->requireInteger($validator, $attribute, $label, $value),
            'json' => $this->requireJson($validator, $attribute, $label, $value),
            default => null,
        };
    }

    private function requireInteger(Validator $validator, string $attribute, string $label, string $value): void
    {
        if (! preg_match('/^-?\d+$/', trim($value))) {
            $validator->errors()->add($attribute, "{$label} must be a whole number.");
        }
    }

    private function requireJson(Validator $validator, string $attribute, string $label, string $value): void
    {
        json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $validator->errors()->add($attribute, "{$label} must be valid JSON.");
        }
    }
}
