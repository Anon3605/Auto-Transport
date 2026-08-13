<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware supplies the identity; the request only ever writes to
        // $request->user(), so there is no other subject to authorize against.
        return $this->user() !== null;
    }

    /**
     * PATCH semantics: every rule is `sometimes`, so an omitted key is left
     * alone rather than nulled.
     *
     * `email` is not accepted here. Swapping the address is an identity change
     * that needs a verification round-trip -- doing it inline would leave a
     * verified flag attached to an address nobody has proven they own.
     *
     * `password` is not accepted either: a password change needs the current
     * password, which is a different endpoint's job.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'required', 'string', 'max:8'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone', 'max:64'],
        ];
    }
}
