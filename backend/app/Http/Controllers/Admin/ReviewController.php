<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateReviewRequest;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The moderation queue. Nothing is public until it passes through this screen
 * (§4.7: status defaults to pending, fail closed).
 *
 * approve() and reject() delegate to the model's methods rather than writing
 * status directly: they stamp the moderator trail, and the observer that rebuilds
 * services.rating_avg / rating_count listens for the save. A raw
 * `update(['status' => ...])` would publish a review without moving the aggregate
 * it belongs to, and the drift would surface on the highest-traffic SEO page in
 * the site.
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $status = $this->filterStatus($request);

        $reviews = Review::query()
            ->with(['user', 'service', 'booking'])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'counts' => $this->counts(),
            'status' => $status?->value,
        ]);
    }

    public function show(Review $review): View
    {
        $review->load([
            'user', 'service', 'carrier', 'driver', 'booking.vehicles',
            'moderatedBy', 'adminRepliedBy', 'media',
        ]);

        return view('admin.reviews.show', ['review' => $review]);
    }

    public function approve(Request $request, Review $review): RedirectResponse
    {
        $review->approve($request->user());

        return back()->with('status', 'Review approved and published.');
    }

    public function reject(ModerateReviewRequest $request, Review $review): RedirectResponse
    {
        $review->reject($request->user(), $request->validated('reason'));

        return back()->with('status', 'Review rejected.');
    }

    /**
     * The owner's response. All three columns are fillable, and admin_replied_by
     * is what makes a reply attributable a year later.
     */
    public function reply(ModerateReviewRequest $request, Review $review): RedirectResponse
    {
        $review->update([
            'admin_reply' => $request->validated('admin_reply'),
            'admin_replied_at' => now(),
            'admin_replied_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Reply posted.');
    }

    /**
     * Homepage testimonial slot. Featuring a pending review would put unmoderated
     * copy on the front page the moment the scope stopped filtering on status, so
     * approval is a precondition rather than a display detail.
     */
    public function feature(Review $review): RedirectResponse
    {
        if ($review->status !== ReviewStatus::Approved) {
            return back()->with('error', 'Approve the review before featuring it.');
        }

        $review->update(['is_featured' => ! $review->is_featured]);

        return back()->with('status', $review->is_featured
            ? 'Review featured on the homepage.'
            : 'Review removed from the homepage.');
    }

    /** Nothing but a real status value filters; a stale bookmark shows everything. */
    private function filterStatus(Request $request): ?ReviewStatus
    {
        return ReviewStatus::tryFrom((string) $request->query('status'));
    }

    /**
     * Queue depth per state, every state present even at zero -- the tab labels
     * are the counts.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = Review::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $all = [];

        foreach (ReviewStatus::cases() as $status) {
            $all[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $all;
    }
}
