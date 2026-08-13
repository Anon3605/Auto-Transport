<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuoteRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The lead queue, read-only. quote_requests is the intake record and §4.1 treats
 * it as immutable history -- a new price is a new Quote row, so pricing a lead
 * belongs to the quote API and not to a form on this page.
 */
class QuoteRequestController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        return view('admin.quotes.index', [
            'quoteRequests' => $this->query($request)->paginate(self::PER_PAGE)->withQueryString(),
            'statuses' => QuoteRequestStatus::cases(),
        ]);
    }

    public function show(QuoteRequest $quoteRequest): View
    {
        $quoteRequest->load([
            'user', 'service', 'assignee', 'vehicles.vehicleType',

            // Newest version first: re-quoting supersedes rather than overwrites,
            // and the current offer is the one at the top.
            'quotes' => fn ($query) => $query->with('issuedBy')->orderByDesc('version'),
        ]);

        return view('admin.quotes.show', ['quoteRequest' => $quoteRequest]);
    }

    /**
     * Filters: status, assigned (a user id, or 'unassigned'), and q across the
     * reference a caller reads out plus the contact details.
     *
     * @return Builder<QuoteRequest>
     */
    private function query(Request $request): Builder
    {
        $query = QuoteRequest::query()
            ->with(['user', 'service', 'assignee', 'latestQuote'])
            ->withCount('quotes')
            ->latest('id');

        if (($status = QuoteRequestStatus::tryFrom((string) $request->query('status'))) !== null) {
            $query->where('status', $status);
        }

        if (($assigned = (string) $request->query('assigned')) !== '') {
            $assigned === 'unassigned'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', (int) $assigned);
        }

        if (($term = trim((string) $request->query('q'))) !== '') {
            $query->where(function (Builder $scoped) use ($term): void {
                $scoped->where('reference', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%")
                    ->orWhere('contact_email', 'like', "%{$term}%")
                    ->orWhere('pickup_city', 'like', "%{$term}%")
                    ->orWhere('dropoff_city', 'like', "%{$term}%");
            });
        }

        return $query;
    }
}
