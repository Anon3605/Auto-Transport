<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors contactSchema in mobile/src/types/schemas.ts.
 *
 * Nothing here is trusted for anything but display in the admin inbox: status,
 * assignment and spam_score are staff-side columns the ContactMessage model
 * deliberately leaves out of $fillable, and ip/user_agent/referrer are read off
 * the request rather than the payload.
 */
class StoreContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:200'],
            // The 10-character floor is contactSchema's, and it is the cheapest
            // spam filter there is: "hi" is never a real enquiry.
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.min' => 'Tell us a little more',
        ];
    }
}
