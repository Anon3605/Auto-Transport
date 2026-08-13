<?php

namespace App\Observers;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Services\RatingAggregator;

/**
 * Keeps the denormalised rating_avg / rating_count pairs in step with the reviews
 * table (design doc §4.7).
 *
 * REGISTRATION: AppServiceProvider::boot() must call
 * Review::observe(ReviewObserver::class). Without it every aggregate silently
 * stops moving and only the nightly reviews:rebuild-aggregates run corrects it --
 * which is exactly the drift §4.7 warns about, just slower.
 *
 * Every handler bails unless an approved review actually entered or left an
 * aggregate. Creating a pending review, rejecting one, replying to one or
 * counting a helpful vote must all cost nothing.
 */
class ReviewObserver
{
    public function __construct(private readonly RatingAggregator $aggregator)
    {
    }

    /**
     * Reviews are created pending, so this is normally a no-op. It earns its keep
     * for rows inserted already-approved: seeders, and the imported legacy or
     * syndicated reviews is_verified exists for.
     */
    public function created(Review $review): void
    {
        if ($this->isApproved($review)) {
            $this->aggregator->forReview($review);
        }
    }

    public function updated(Review $review): void
    {
        $wasApproved = $this->wasApproved($review);

        // pending -> rejected, and every other move between two non-public
        // states, leaves all three aggregates exactly as they were.
        if (! $wasApproved && ! $this->isApproved($review)) {
            return;
        }

        // An approved review can be edited without moving a number: an admin_reply,
        // the homepage-testimonial flag, a moderator note.
        if (! $review->wasChanged(['status', 'rating_overall', 'service_id', 'carrier_id', 'driver_id'])) {
            return;
        }

        $this->aggregator->forReview($review);

        // A moderator re-pointing an already-approved review at a different
        // service or carrier has to leave the OLD subject's average correct too;
        // the current snapshot no longer names it.
        if ($wasApproved && $review->wasChanged(['service_id', 'carrier_id', 'driver_id'])) {
            $this->aggregator->forSnapshot(
                $this->originalId($review, 'service_id'),
                $this->originalId($review, 'carrier_id'),
                $this->originalId($review, 'driver_id'),
            );
        }
    }

    /**
     * Fires for a soft delete and for a force delete. In both cases the row is
     * already gone from the aggregator's queries by the time we run, so a plain
     * recount is the correct response.
     */
    public function deleted(Review $review): void
    {
        if ($this->isApproved($review)) {
            $this->aggregator->forReview($review);
        }
    }

    /** restore() also fires updated(), but only deleted_at changed there. */
    public function restored(Review $review): void
    {
        if ($this->isApproved($review)) {
            $this->aggregator->forReview($review);
        }
    }

    private function isApproved(Review $review): bool
    {
        return $review->status === ReviewStatus::Approved;
    }

    /** Compared raw: getOriginal() would cast, and one enum path is enough. */
    private function wasApproved(Review $review): bool
    {
        return $review->getRawOriginal('status') === ReviewStatus::Approved->value;
    }

    private function originalId(Review $review, string $column): ?int
    {
        $value = $review->getRawOriginal($column);

        return $value === null ? null : (int) $value;
    }
}
