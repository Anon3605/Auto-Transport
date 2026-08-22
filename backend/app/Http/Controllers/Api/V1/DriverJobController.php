<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The driver's job list.
 *
 * This exists because a driver is NOT a customer, and the customer endpoint
 * cannot serve them: GET /bookings scopes on `user_id`, so a driver hitting it
 * sees shipments they personally own — which is almost always none — rather than
 * the loads assigned to them. Before this controller, the driver's app was
 * literally the customer app showing an empty list.
 *
 * The distinction is `bookings.driver_id`, and the query below is what
 * bookings_driver_idx (driver_id, status) was indexed for.
 */
class DriverJobController extends Controller
{
    use AuthorizesRequests;

    /**
     * Transitions a driver may perform from the cab.
     *
     * Deliberately narrower than the full state machine: confirming an order is a
     * commercial decision and cancelling has refund consequences, so both stay
     * with dispatch. A driver reports what physically happened to the vehicle,
     * nothing more.
     *
     * @var array<string, string>
     */
    private const DRIVER_TRANSITIONS = [
        'picked_up' => 'Collected from the pickup address.',
        'in_transit' => 'On the road.',
        'delivered' => 'Delivered to the dropoff address.',
    ];

    /**
     * Active jobs, oldest scheduled pickup first — a work list, not a feed.
     *
     * Delivered and cancelled loads are excluded by default: a driver opening the
     * app wants today's run, and last month's completed jobs push it below the
     * fold. `?include=all` is there for when they need to look something up.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Booking::query()
            ->with(['service', 'vehicles.vehicleType'])
            ->where('driver_id', $request->user()->id);

        if ($request->query('include') !== 'all') {
            $query->whereNotIn('status', [
                BookingStatus::Delivered->value,
                BookingStatus::Cancelled->value,
            ]);
        }

        return BookingResource::collection(
            $query->orderByRaw('scheduled_pickup_date is null, scheduled_pickup_date asc')->paginate(20)
        );
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->assertAssignedToCaller($request, $booking);

        return BookingResource::make(
            $booking->load(['service', 'vehicles.vehicleType', 'events' => fn ($q) => $q->chronological()])
        )->response();
    }

    /**
     * Report progress. Goes through Booking::transitionTo(), so the status and its
     * timeline event are written together and an illegal move is refused by the
     * state machine rather than by a check duplicated here.
     */
    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $this->assertAssignedToCaller($request, $booking);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(self::DRIVER_TRANSITIONS))],
            'note' => ['nullable', 'string', 'max:500'],
            // Location is optional: a pickup in an underground car park has no
            // signal, and refusing the update would lose the timestamp entirely.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $next = BookingStatus::from($validated['status']);

        try {
            $booking->transitionTo($next, $validated['note'] ?? self::DRIVER_TRANSITIONS[$validated['status']]);
        } catch (DomainException $e) {
            // A stale screen, not a bug: the driver tapped "Delivered" on a job
            // dispatch already closed. 422 so the app can show the reason.
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        // Recorded as its own event so the position history stays intrinsically
        // ordered by occurred_at without a separate tracking table (§4.8).
        if (isset($validated['lat'], $validated['lng'])) {
            $booking->recordEvent('location_ping', [
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
                'is_customer_visible' => false,
            ]);
        }

        return BookingResource::make($booking->fresh(['service']))->response();
    }

    /**
     * 404, not 403: a driver has no business learning that a booking exists just
     * because they guessed a ULID that is not theirs.
     */
    private function assertAssignedToCaller(Request $request, Booking $booking): void
    {
        abort_if(
            (int) $booking->driver_id !== (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
            'Resource not found.'
        );
    }
}
