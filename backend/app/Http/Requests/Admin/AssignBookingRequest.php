<?php

namespace App\Http\Requests\Admin;

use App\Models\Carrier;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dispatch assignment: carrier, driver and truck.
 *
 * All three are nullable so a dispatcher can book a carrier now and name the
 * driver later — that is the real sequence, since the carrier is engaged before
 * they say which of their drivers is running it. Submitting a field empty
 * un-assigns it.
 *
 * NOTE ON THE RULES BELOW. An earlier version tried to prove the driver role
 * inside the `exists` rule:
 *
 *     Rule::exists('model_has_roles', 'model_id')
 *         ->where('role_id', fn ($q) => $q->select('id')->from('roles')...)
 *
 * That 500s. `DatabaseRule::where()` treats its second argument as a plain value
 * and stringifies it when building the rule, so a Closure reaches str_replace()
 * and throws a TypeError before validation even runs. The rule builder is not a
 * query builder, and only `->using(Closure)` accepts one.
 *
 * So the role and employment checks live in after() instead, expressed against
 * the model where they are legible and where Spatie can answer them.
 */
class AssignBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_bookings') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'carrier_id' => [
                'nullable',
                'integer',
                Rule::exists('carriers', 'id')->whereNull('deleted_at'),
            ],

            /*
             * driver_id points at `users`, not `driver_profiles` — a driver is a
             * user who also happens to have a profile. Existence only here; that
             * the user is actually a driver, and employed by the chosen carrier,
             * is decided in after().
             */
            'driver_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],

            'truck_id' => ['nullable', 'integer', Rule::exists('trucks', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'driver_id.exists' => 'We could not find that driver.',
            'carrier_id.exists' => 'We could not find that carrier.',
            'truck_id.exists' => 'We could not find that truck.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $carrierId = $this->integerOrNull('carrier_id');
                $driverId = $this->integerOrNull('driver_id');
                $truckId = $this->integerOrNull('truck_id');

                /*
                 * Checked whether or not a carrier was chosen: assigning a
                 * shipment to a customer because someone pasted the wrong id is
                 * the failure this prevents, and it does not depend on a carrier.
                 */
                if ($driverId !== null) {
                    $driver = User::query()->find($driverId);

                    if ($driver === null || ! $driver->hasRole('driver')) {
                        $validator->errors()->add('driver_id', 'That user is not a driver.');

                        // No point testing employment against a non-driver.
                        $driverId = null;
                    }
                }

                if ($carrierId === null) {
                    // Nothing further to cross-check: a driver or truck without a
                    // carrier is an incomplete assignment, not an invalid one.
                    return;
                }

                /*
                 * A truck belongs to a carrier. Assigning carrier A's trailer to a
                 * load run by carrier B is the kind of mistake that only surfaces
                 * when a driver arrives at a pickup in the wrong equipment, so it
                 * is refused here rather than left for a human to notice.
                 */
                if ($truckId !== null) {
                    $belongs = Carrier::query()
                        ->whereKey($carrierId)
                        ->whereHas('trucks', fn ($query) => $query->whereKey($truckId))
                        ->exists();

                    if (! $belongs) {
                        $validator->errors()->add('truck_id', 'That truck belongs to a different carrier.');
                    }
                }

                if ($driverId !== null) {
                    $employed = Carrier::query()
                        ->whereKey($carrierId)
                        ->whereHas('driverProfiles', fn ($query) => $query->where('user_id', $driverId))
                        ->exists();

                    if (! $employed) {
                        $validator->errors()->add('driver_id', 'That driver does not work for the selected carrier.');
                    }
                }
            },
        ];
    }

    private function integerOrNull(string $key): ?int
    {
        $value = $this->input($key);

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /** @return array{carrier_id: ?int, driver_id: ?int, truck_id: ?int} */
    public function assignment(): array
    {
        return [
            'carrier_id' => $this->integerOrNull('carrier_id'),
            'driver_id' => $this->integerOrNull('driver_id'),
            'truck_id' => $this->integerOrNull('truck_id'),
        ];
    }
}
