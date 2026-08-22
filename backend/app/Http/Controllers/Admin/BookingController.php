<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignBookingRequest;
use App\Actions\RecordPayment;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Models\Booking;
use App\Models\Carrier;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Dispatch board. Two things separate it from the customer-facing booking API:
 * the timeline here includes internal events, and status moves are driven by
 * hand instead of by the driver's app.
 */
class BookingController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        return view('admin.bookings.index', [
            'bookings' => $this->query($request)->paginate(self::PER_PAGE)->withQueryString(),
            'statuses' => BookingStatus::cases(),
        ]);
    }

    public function show(Booking $booking): View
    {
        $booking->load([
            'user', 'service', 'quote', 'quoteRequest', 'carrier', 'driver', 'truck',
            'vehicles.vehicleType', 'payments', 'review', 'media',

            // chronological(), not latest(): occurred_at is when it happened,
            // created_at is when the phone finally had signal (§4.8).
            'events' => fn ($query) => $query->with('createdBy')->chronological(),
        ]);

        return view('admin.bookings.show', [
            'booking' => $booking,

            // The whole timeline, internal chatter included. The API filters this
            // to customerVisible(); the panel is the other audience.
            'events' => $booking->events,

            // Feeds the status form. The state machine rejects anything else, so
            // offering the full enum would only manufacture flash errors.
            'allowedTransitions' => $booking->status->allowedNext(),

            /*
             * Assignment options. Carriers carry their drivers and trucks with them
             * so the form can be filtered client-side without a round trip — and,
             * more importantly, so the request-level check that a truck belongs to
             * the chosen carrier has something to validate against.
             */
            'carriers' => Carrier::query()
                ->with([
                    'driverProfiles.user:id,name',
                    'trucks:id,carrier_id,unit_number,trailer_type,is_active',
                ])
                ->orderBy('company_name')
                ->get(),
        ]);
    }



    /**
     * Record money received. Manual entry: there is no gateway, so a human is
     * asserting the money arrived and the row records who.
     *
     * A DomainException here means the idempotency key was reused — a
     * double-submitted form. That is a flash message, not a 500.
     */
    public function payment(RecordPaymentRequest $request, Booking $booking, RecordPayment $recordPayment): RedirectResponse
    {
        try {
            $recordPayment->handle($booking, $request->user(), $request->validated());
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.bookings.show', $booking)
                ->with('error', $e->getMessage());
        }

        activity()
            ->performedOn($booking)
            ->causedBy($request->user())
            ->log("Payment recorded on {$booking->booking_number}");

        return redirect()
            ->route('admin.bookings.show', $booking)
            ->with('status', 'Payment recorded.');
    }
    /**
     * Dispatch assignment. Separate from status() because assigning a carrier is
     * not a state transition — a booking can gain a driver while staying
     * `confirmed`, and forcing the two through one endpoint would mean either
     * moving the status as a side effect of assignment or refusing assignment
     * outside one particular state.
     *
     * The timeline event is internal: which of a carrier's drivers is running the
     * load is operational detail, and a customer watching the tracking screen does
     * not need a notification every time dispatch reshuffles.
     */
    public function assign(AssignBookingRequest $request, Booking $booking): RedirectResponse
    {
        $assignment = $request->assignment();

        $booking->fill($assignment)->save();

        $booking->recordEvent('assignment_changed', [
            'description' => sprintf(
                'Assigned carrier: %s, driver: %s, truck: %s.',
                $booking->carrier?->company_name ?? 'none',
                $booking->driver?->name ?? 'none',
                $booking->truck?->unit_number ?? 'none',
            ),
            'is_customer_visible' => false,
        ]);

        activity()
            ->performedOn($booking)
            ->causedBy($request->user())
            ->log("Assignment updated on {$booking->booking_number}");

        return redirect()
            ->route('admin.bookings.show', $booking)
            ->with('status', 'Assignment saved.');
    }
    /**
     * Every status move goes through Booking::transitionTo(), which writes the
     * status and its timeline event in one transaction. An illegal move is a
     * DomainException by design; it is a mis-click on a stale page, not a bug, so
     * it comes back as a flash message rather than a 500.
     */
    public function status(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_column(BookingStatus::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $next = BookingStatus::from($validated['status']);

        try {
            $booking->transitionTo($next, $validated['description'] ?? null);
        } catch (DomainException $e) {
            // Logged because a legal-looking button that produces this means the
            // page was rendered from a status the booking has since left.
            Log::info('Rejected admin booking transition', [
                'booking' => $booking->booking_number,
                'reason' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Booking {$booking->booking_number} is now {$next->label()}.");
    }

    /**
     * Filters, all optional and all combinable:
     *   status  one BookingStatus value
     *   from/to inclusive window on scheduled_pickup_date -- the dispatch
     *           question is "what leaves this week", not "what was booked"
     *   q       booking number, customer name or either city
     *
     * @return Builder<Booking>
     */
    private function query(Request $request): Builder
    {
        $query = Booking::query()
            ->with(['user', 'service', 'carrier', 'driver'])
            ->latest('id');

        if (($status = BookingStatus::tryFrom((string) $request->query('status'))) !== null) {
            $query->where('status', $status);
        }

        if (($from = $this->date($request->query('from'))) !== null) {
            $query->whereDate('scheduled_pickup_date', '>=', $from);
        }

        if (($to = $this->date($request->query('to'))) !== null) {
            $query->whereDate('scheduled_pickup_date', '<=', $to);
        }

        if (($term = trim((string) $request->query('q'))) !== '') {
            $query->where(function (Builder $scoped) use ($term): void {
                $scoped->where('booking_number', 'like', "%{$term}%")
                    ->orWhere('pickup_city', 'like', "%{$term}%")
                    ->orWhere('dropoff_city', 'like', "%{$term}%")
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%"));
            });
        }

        return $query;
    }

    /** A half-typed date in the filter box must narrow nothing, not throw. */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
