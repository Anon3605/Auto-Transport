<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreContactMessageRequest;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ContactMessageController extends Controller
{
    /**
     * Public contact form. Open to guests, so throttling is the route's job.
     *
     * The response is a flat acknowledgement with no record in it: the caller may
     * be anonymous, and handing back a ulid it could quote at a later endpoint
     * buys the sender nothing while giving an abuser a receipt to enumerate.
     */
    public function store(StoreContactMessageRequest $request): JsonResponse
    {
        $message = new ContactMessage($request->validated());

        // Forensics, read off the request and never off the payload. user_agent
        // and referrer are VARCHAR(512) columns; a crafted 8KB header must not
        // turn a contact form into a 500.
        $message->ip_address = $request->ip();
        $message->user_agent = $this->clamp($request->userAgent(), 512);
        $message->referrer = $this->clamp($request->headers->get('referer'), 512);

        // user_id is not fillable (staff-side column), so it is assigned directly.
        // Set when a signed-in customer writes in, which is what lets support see
        // their bookings alongside the message.
        $message->user_id = $request->user()?->id;

        $message->save();

        return response()->json([
            'message' => 'Thanks for getting in touch. Our team will reply shortly.',
        ], Response::HTTP_CREATED);
    }

    private function clamp(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
