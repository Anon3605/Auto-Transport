<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every moderator-supplied string that reaches a review passes through here, so
 * there is one file to read when asking what the panel may write to a review.
 *
 * Rules are keyed on the action being called because reject and reply take one
 * field each and share nothing else; splitting them into two near-identical
 * classes would only spread the answer over more files.
 *
 * `is_verified` appears in neither branch. Design doc §4.7: it means "we hold
 * the shipment record", it is derived from the booking linkage, and it must
 * never become an admin toggle -- the model leaves it out of $fillable for the
 * same reason.
 */
class ModerateReviewRequest extends FormRequest
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
        return match ($this->route()?->getActionMethod()) {
            // rejection_reason is VARCHAR(255); the customer is told why, so a
            // truncated explanation is worse than a rejected form.
            'reject' => [
                'reason' => ['required', 'string', 'min:3', 'max:255'],
            ],
            'reply' => [
                'admin_reply' => ['required', 'string', 'min:3', 'max:5000'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason -- rejection is auditable and the customer is told why.',
            'admin_reply.required' => 'Write a reply before posting it.',
        ];
    }
}
