<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\BookService;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Http\Resources\BookingEventResource;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Service;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * "My shipments" -- the authenticated heart of the app. Every action here is
 * scoped to the caller by the query AND checked by BookingPolicy: the ULID in
 * the URL is unguessable, not authorization (§4.5).
 */
class BookingController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 15;

    /**
     * The app's hottest query, which is what bookings_user_status_idx
     * (user_id, status) exists for -- hence the optional status filter reusing
     * the same index rather than a separate endpoint.
     *
     * `service` is eager-loaded because BookingResource emits a service summary
     * on every row: without it, a 15-row page costs 16 queries.
     */
    /**
     * Book a service from the app.
     *
     * Delegates to BookService, which writes quote_request -> quote -> booking in
     * one transaction so an instantly-booked shipment still has the intake and
     * offer history every other booking has (§4.1).
     *
     * The shipment opens at `pending_payment`: the price is the automated
     * estimate, and §7 is explicit that an estimate is not a binding quote, so a
     * human still confirms before it reaches the dispatch board.
     */
    public function store(StoreBookingRequest $request, BookService $bookService): JsonResponse
    {
        $this->authorize('create', Booking::class);

        $service = Service::query()
            ->where('slug', $request->validated('service_slug'))
            ->firstOrFail();

        $booking = $bookService->handle($request->user(), $service, $request->validated());

        return BookingResource::make($booking->load(['service', 'vehicles.vehicleType']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
        ]);

        $bookings = Booking::query()
            ->forUser($request->user())
            ->with('service')
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status'])
            )
            // Newest first, id breaking the tie: two bookings created in the same
            // second must not swap places between pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return BookingResource::collection($bookings);
    }

    public function show(Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);

        return new BookingResource($booking->loadMissing('service'));
    }

    /**
     * The tracking timeline. is_customer_visible is filtered in the query so
     * dispatch's internal notes ("carrier ghosting, rebooking") never leave the
     * building -- design doc §4.8. Ordered by occurred_at, not created_at,
     * because an offline driver ping arrives late but happened early.
     */
    public function events(Booking $booking): AnonymousResourceCollection
    {
        $this->authorize('view', $booking);

        return BookingEventResource::collection(
            $booking->events()->customerVisible()->chronological()->get()
        );
    }

    /**
     * Customer-initiated cancellation. Goes through Booking::transitionTo() so the
     * status change and its timeline event are written together (§4.8) -- and so
     * BookingStatus, not this controller, decides whether the move is legal.
     */
    public function cancel(Request $request, Booking $booking): BookingResource|JsonResponse
    {
        $this->authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = $validated['reason'] ?? null;

        // Filled, not saved: transitionTo() calls save() on this same instance, so
        // the reason and the status land in one UPDATE inside its transaction. An
        // illegal transition throws before that save and writes nothing.
        $booking->fill([
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
        ]);

        try {
            $booking->transitionTo(
                BookingStatus::Cancelled,
                $reason === null ? 'Cancelled by customer.' : 'Cancelled by customer: '.$reason,
            );
        } catch (DomainException) {
            /**
             * A shipment already picked up, in transit or delivered cannot be
             * cancelled -- that is a business rule the customer is entitled to
             * read, so it comes back as a 422 in the client's error-bag shape
             * rather than as the 500 the raw DomainException would produce.
             */
            return response()->json([
                'message' => sprintf(
                    'This shipment can no longer be cancelled because it is already %s. Contact support if you need help.',
                    $booking->status->label(),
                ),
                'errors' => ['status' => ['Cancellation is not available at this stage.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new BookingResource($booking->loadMissing('service'));
    }
}
