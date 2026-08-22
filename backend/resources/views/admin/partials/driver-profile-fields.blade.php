{{--
    Driver profile fields on the account form.

    THIS IS THE APPROVAL SWITCH. A self-registered driver arrives with
    `status = pending` and `carrier_id = null`, and both have to change before
    they can be given work:

      - status  -> active   so the account can transact at all
      - carrier -> employer so AssignBookingRequest will accept them, because it
                   refuses to assign a driver who does not work for the selected
                   carrier

    Setting only the status leaves a driver who looks approved and is still
    unassignable, which is a confusing place to leave somebody. The hint below
    says so on the screen rather than only here.

    @param \App\Models\User $user
    @param \Illuminate\Support\Collection $carriers
--}}
@php
    $profile = $user->driverProfile;
    $needsCarrier = $profile === null || $profile->carrier_id === null;
@endphp

<section class="card">
    <div class="card__head">
        <h2 class="card__title">Driver details</h2>
        @if ($user->status === 'pending' || $needsCarrier)
            <div class="card__actions">
                <span class="badge badge--warning">Awaiting approval</span>
            </div>
        @endif
    </div>

    <div class="card__body">
        @if ($user->status === 'pending' || $needsCarrier)
            <div class="alert alert--warning">
                <span class="alert__icon" aria-hidden="true">&#9888;</span>
                <div class="alert__body">
                    <p class="alert__title">This driver cannot be given work yet.</p>
                    <ul>
                        @if ($user->status !== 'active')
                            <li>Account status is <strong>{{ $user->status }}</strong> — set it to <strong>active</strong> above.</li>
                        @endif
                        @if ($needsCarrier)
                            <li>No carrier is linked — dispatch can only assign a driver to loads run by their own employer.</li>
                        @endif
                    </ul>
                </div>
            </div>
        @endif

        <div class="form-grid">
            <div class="form-field">
                <label class="form-label" for="driver_carrier_id">Carrier (employer)</label>
                <select class="select" name="driver[carrier_id]" id="driver_carrier_id">
                    <option value="">Not employed</option>
                    @foreach ($carriers as $carrier)
                        <option value="{{ $carrier->id }}"
                            @selected((int) old('driver.carrier_id', $profile->carrier_id ?? null) === $carrier->id)>
                            {{ $carrier->company_name }}
                        </option>
                    @endforeach
                </select>
                @error('driver.carrier_id')<p class="form-error">{{ $message }}</p>@enderror
                <p class="form-hint">Clearing this un-assigns the driver from their employer.</p>
            </div>

            <div class="form-field">
                <label class="form-label" for="driver_cdl_class">Licence class</label>
                <select class="select" name="driver[cdl_class]" id="driver_cdl_class">
                    @foreach (['' => 'Not recorded', 'A' => 'Class A', 'B' => 'Class B', 'C' => 'Class C', 'none' => 'No CDL'] as $value => $label)
                        <option value="{{ $value }}"
                            @selected((string) old('driver.cdl_class', $profile->cdl_class ?? '') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('driver.cdl_class')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <label class="form-label" for="driver_license_number">Licence number</label>
                <input class="input mono" type="text" name="driver[license_number]" id="driver_license_number"
                       maxlength="64" value="{{ old('driver.license_number', $profile->license_number ?? '') }}">
                @error('driver.license_number')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <label class="form-label" for="driver_license_state">Issuing state</label>
                <input class="input" type="text" name="driver[license_state]" id="driver_license_state"
                       maxlength="64" value="{{ old('driver.license_state', $profile->license_state ?? '') }}">
                @error('driver.license_state')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <label class="form-label" for="driver_license_expires_at">Licence expires</label>
                <input class="input" type="date" name="driver[license_expires_at]" id="driver_license_expires_at"
                       value="{{ old('driver.license_expires_at', $profile?->license_expires_at?->toDateString() ?? '') }}">
                @error('driver.license_expires_at')<p class="form-error">{{ $message }}</p>@enderror
                @if ($profile?->license_expires_at !== null && $profile->license_expires_at->isPast())
                    {{-- Stated loudly: an expired licence is the one fact that makes
                         the person unable to do the job today. --}}
                    <p class="form-error">This licence has expired.</p>
                @endif
            </div>

            <div class="form-field">
                {{-- Hidden 0 before the checkbox: unchecked sends nothing, and
                     "absent" would be indistinguishable from "explicitly false". --}}
                <input type="hidden" name="driver[is_available]" value="0">
                <label class="check">
                    <input type="checkbox" name="driver[is_available]" value="1"
                           @checked(old('driver.is_available', $profile->is_available ?? false))>
                    <span class="check__text">
                        Available for dispatch
                        <span class="check__hint">Off for applicants and anyone on leave.</span>
                    </span>
                </label>
                @error('driver.is_available')<p class="form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>
</section>
