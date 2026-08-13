<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\AddressResource;
use App\Http\Resources\UserResource;
use App\Models\Address;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The signed-in customer's own account: their profile and their saved address
 * book. Every query in here starts from $request->user(), so there is no id in
 * any payload that could point at somebody else's row.
 */
class ProfileController extends Controller
{
    /** Mirrors addresses.location_type in 2026_01_01_000100 and AddressInput in mobile/src/types/api.ts. */
    private const LOCATION_TYPES = ['residential', 'business', 'terminal', 'auction', 'dealer', 'port'];

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->loadMissing('roles')),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        // fill(), not update(): assigning `phone` runs the model's set mutator so
        // phone_normalized cannot drift out of step with it.
        $user->fill($request->validated())->save();

        return response()->json([
            'user' => new UserResource($user->loadMissing('roles')),
        ]);
    }

    public function addresses(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $request->user()->addresses()
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->orderBy('id')
                ->get()
        );
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedAddress($request);

        $address = DB::transaction(function () use ($user, $data): Address {
            // The first saved address is the default whatever the client asked
            // for: a book with no default makes every prefilled form guess.
            $data['is_default'] = ! empty($data['is_default']) || $user->addresses()->doesntExist();

            $address = $user->addresses()->create($data);

            if ($address->is_default) {
                $this->demoteOtherDefaults($user, $address);
            }

            // Re-read so the response carries the column defaults (country_code,
            // location_type) rather than nulls for keys the client left out.
            return $address->refresh();
        });

        return (new AddressResource($address))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateAddress(Request $request, Address $address): JsonResponse
    {
        $this->assertOwned($request, $address);

        $data = $this->validatedAddress($request, partial: true);

        DB::transaction(function () use ($request, $address, $data): void {
            $address->fill($data)->save();

            if ($address->is_default) {
                $this->demoteOtherDefaults($request->user(), $address);
            }
        });

        return (new AddressResource($address))->response();
    }

    public function destroyAddress(Request $request, Address $address): Response
    {
        $this->assertOwned($request, $address);

        DB::transaction(function () use ($request, $address): void {
            $wasDefault = $address->is_default;

            // Soft delete: a booking made from this address keeps its own snapshot
            // (§4.3), but support still needs to see what the customer had saved.
            $address->delete();

            if ($wasDefault) {
                $request->user()->addresses()->oldest()->first()?->update(['is_default' => true]);
            }
        });

        return response()->noContent();
    }

    /**
     * Ownership, never the id in the URL. 404 rather than 403 so a probe cannot
     * learn that somebody else's row exists.
     */
    private function assertOwned(Request $request, Address $address): void
    {
        abort_unless($address->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);
    }

    /**
     * Exactly one default per user. sqlite has no partial unique index to lean
     * on, so the invariant is re-established on every write that claims it.
     */
    private function demoteOtherDefaults(User $user, Address $address): void
    {
        $user->addresses()
            ->whereKeyNot($address->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAddress(Request $request, bool $partial = false): array
    {
        $data = $request->validate($this->addressRules($partial));

        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);   // char(2), compared against ISO codes elsewhere
        }

        return $data;
    }

    /**
     * Lengths track the addresses table exactly; anything looser would surface a
     * driver truncation error as a 500 instead of a field message.
     *
     * @return array<string, array<int, mixed>>
     */
    private function addressRules(bool $partial): array
    {
        $rules = [
            'label' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:24'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'location_type' => ['sometimes', 'required', Rule::in(self::LOCATION_TYPES)],
            'is_default' => ['sometimes', 'boolean'],
        ];

        if (! $partial) {
            return $rules;
        }

        // PATCH semantics: an absent key means "leave it alone", so every rule
        // that is not already conditional becomes one.
        return array_map(
            fn (array $rule): array => in_array('sometimes', $rule, true) ? $rule : array_merge(['sometimes'], $rule),
            $rules,
        );
    }
}
