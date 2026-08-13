<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\QuoteRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ContactMessage;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The panel's landing page. Every tile is gated on the same permission as the
 * screen it links to, so a support agent's dashboard is not a listing of the
 * numbers they are not allowed to open.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('admin.dashboard', [
            'stats' => $this->stats($user),
            'statusCounts' => $user->can('view_bookings') ? $this->bookingStatusCounts() : [],
            'recentBookings' => $user->can('view_bookings') ? $this->recentBookings() : new Collection,
            'pendingReviews' => $user->can('view_reviews') ? $this->pendingReviews() : new Collection,
        ]);
    }

    /**
     * label => ['value' => string|int, 'hint' => ?string]. The hint says what the
     * number counts, which is the difference between a dashboard and a wall of
     * unlabelled integers.
     *
     * @return array<string, array{value: string|int, hint: ?string}>
     */
    private function stats(User $user): array
    {
        $stats = [];

        if ($user->can('view_quotes')) {
            $stats['Open leads'] = [
                'value' => QuoteRequest::query()
                    ->whereIn('status', $this->openQuoteStatuses())
                    ->count(),
                'hint' => 'New, reviewing or quoted',
            ];
        }

        if ($user->can('view_bookings')) {
            $stats['Active shipments'] = [
                'value' => Booking::query()
                    ->whereIn('status', $this->activeBookingStatuses())
                    ->count(),
                'hint' => 'Confirmed through in transit',
            ];
        }

        if ($user->can('view_reviews')) {
            $stats['Awaiting moderation'] = [
                'value' => Review::query()->pending()->count(),
                'hint' => 'Nothing is public until approved',
            ];
        }

        if ($user->can('view_contact_messages')) {
            $stats['Unread messages'] = [
                'value' => ContactMessage::query()->status(ContactMessage::STATUS_NEW)->count(),
                'hint' => 'Contact form, never opened',
            ];
        }

        if ($user->can('manage_bookings')) {
            $stats['Captured (30 days)'] = [
                'value' => $this->formatMoney($this->netCapturedCents(30)),
                'hint' => 'Captures less refunds and chargebacks',
            ];
        }

        if ($user->can('view_users')) {
            $stats['Active accounts'] = [
                'value' => User::query()->active()->count(),
                'hint' => 'Customers, drivers and staff',
            ];
        }

        return $stats;
    }

    /**
     * Every status is present even at zero -- a bar chart that silently drops
     * empty states reads as "no cancellations" rather than "none this month".
     *
     * @return array<string, int>
     */
    private function bookingStatusCounts(): array
    {
        $counts = Booking::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $all = [];

        foreach (BookingStatus::cases() as $status) {
            $all[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $all;
    }

    /** @return Collection<int, Booking> */
    private function recentBookings(): Collection
    {
        return Booking::query()
            ->with(['user', 'service'])
            ->latest()
            ->limit(8)
            ->get();
    }

    /** @return Collection<int, Review> */
    private function pendingReviews(): Collection
    {
        return Review::query()
            ->pending()
            ->with(['user', 'service', 'booking'])
            ->latest()
            ->limit(5)
            ->get();
    }

    /**
     * Mirrors Payment::signedAmountCents(): only captured rows count, and
     * direction comes from the type because amount_cents is unsigned (§4.11).
     * Rows with no paid_at are outside the window by definition -- an
     * unstamped capture is a reconciliation problem, not revenue.
     */
    private function netCapturedCents(int $days): int
    {
        $since = now()->subDays($days);

        $sum = fn (bool $outbound): int => (int) Payment::query()
            ->captured()
            ->where('paid_at', '>=', $since)
            ->whereIn('type', $outbound ? Payment::OUTBOUND_TYPES : [
                Payment::TYPE_DEPOSIT, Payment::TYPE_BALANCE, Payment::TYPE_FULL,
            ])
            ->sum('amount_cents');

        return $sum(false) - $sum(true);
    }

    /** @return list<string> */
    private function openQuoteStatuses(): array
    {
        return array_values(array_map(
            fn (QuoteRequestStatus $status): string => $status->value,
            array_filter(QuoteRequestStatus::cases(), fn (QuoteRequestStatus $s): bool => $s->isOpen()),
        ));
    }

    /** Terminal states and the unpaid ones are not work in progress. @return list<string> */
    private function activeBookingStatuses(): array
    {
        return [
            BookingStatus::Confirmed->value,
            BookingStatus::Assigned->value,
            BookingStatus::PickedUp->value,
            BookingStatus::InTransit->value,
        ];
    }

    /** Division by 100 happens here, on the already-summed total, and nowhere else (§4.4). */
    private function formatMoney(int $cents, string $currency = 'USD'): string
    {
        $amount = number_format($cents / 100, 2);

        return $currency === 'USD' ? '$'.$amount : $currency.' '.$amount;
    }
}
