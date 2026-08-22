<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the `User` interface in mobile/src/types/api.ts field for field.
 * Nothing about the account's security state -- failed_login_count,
 * locked_until, two-factor material -- crosses this boundary.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            // The client types roles as string[], never optional, so the key is
            // always present: an eager-loaded relation is read straight off the
            // collection, and a caller that forgot to load it pays for a lazy
            // read rather than shipping a payload the client cannot parse.

            /*
             * The app has to be able to tell "no work assigned yet" from "your
             * account is not approved". Both render as an empty job list without
             * this, and an empty list reads as a broken app.
             */
            'status' => $this->status,
            'is_active' => $this->status === 'active',
            'roles' => $this->whenLoaded(
                'roles',
                fn (): array => $this->roles->pluck('name')->all(),
                fn (): array => $this->getRoleNames()->all(),
            ),
            'email_verified' => $this->email_verified_at !== null,
        ];
    }
}
