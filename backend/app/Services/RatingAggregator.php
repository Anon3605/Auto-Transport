<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\DriverProfile;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Maintains the denormalised rating_avg / rating_count pairs on services,
 * carriers and driver_profiles (design doc §4.7).
 *
 * Every method recomputes from the approved reviews rather than incrementing.
 * An increment is one lost event away from being permanently wrong; a recount
 * is self-healing, and it is the same code path the nightly
 * `reviews:rebuild-aggregates` command runs, so the fast path and the repair
 * path cannot drift apart.
 */
class RatingAggregator
{
    public function forService(Service $service): void
    {
        $this->write($service, $service->approvedReviews());
    }

    public function forCarrier(Carrier $carrier): void
    {
        $this->write($carrier, $carrier->approvedReviews());
    }

    /**
     * reviews.driver_id points at users.id, not driver_profiles.id -- the profile
     * row is just where the aggregate is stored.
     */
    public function forDriver(DriverProfile $driver): void
    {
        $this->write(
            $driver,
            Review::query()->approved()->where('driver_id', $driver->user_id)
        );
    }

    /**
     * Refresh whichever subjects this review is attached to. All three columns
     * are nullable snapshots, so a review can legitimately count towards a
     * service but no carrier.
     */
    public function forReview(Review $review): void
    {
        $this->forSnapshot($review->service_id, $review->carrier_id, $review->driver_id);
    }

    /**
     * Same work addressed by raw ids, for the case forReview() cannot express:
     * a review whose snapshot was just re-pointed, where the aggregate the row
     * has LEFT also has to be corrected. The ids come from getRawOriginal(), so
     * there is no model instance to hand.
     *
     * withTrashed on both lookups: a soft-deleted service or carrier can be
     * restored, and it must not come back carrying a stale average.
     *
     * @param  int|null  $driverUserId  reviews.driver_id references users.id
     */
    public function forSnapshot(?int $serviceId, ?int $carrierId, ?int $driverUserId): void
    {
        if ($serviceId !== null) {
            $service = Service::query()->withTrashed()->find($serviceId);

            if ($service !== null) {
                $this->forService($service);
            }
        }

        if ($carrierId !== null) {
            $carrier = Carrier::query()->withTrashed()->find($carrierId);

            if ($carrier !== null) {
                $this->forCarrier($carrier);
            }
        }

        if ($driverUserId !== null) {
            $profile = DriverProfile::query()->where('user_id', $driverUserId)->first();

            if ($profile !== null) {
                $this->forDriver($profile);
            }
        }
    }

    /**
     * @param  Relation<covariant Model, Model, mixed>|Builder<covariant Model>  $approved
     */
    private function write(Model $subject, Relation|Builder $approved): void
    {
        [$count, $avg] = $this->stats($approved);

        // rating_avg / rating_count are excluded from every $fillable on purpose;
        // forceFill is the sanctioned way in. Quietly, because an aggregate
        // refresh is not a change to the service itself and must not trigger
        // whatever else listens to a Service/Carrier save.
        $subject->forceFill([
            'rating_count' => $count,
            'rating_avg' => $avg,
        ])->saveQuietly();
    }

    /**
     * One round trip for both numbers.
     *
     * @param  Relation<covariant Model, Model, mixed>|Builder<covariant Model>  $approved
     * @return array{0: int, 1: float}
     */
    private function stats(Relation|Builder $approved): array
    {
        $row = $approved->toBase()
            ->selectRaw('COUNT(*) as reviews_total, AVG(rating_overall) as rating_mean')
            ->first();

        $count = (int) ($row->reviews_total ?? 0);

        // SQL AVG() over an empty set is NULL, and the columns are NOT NULL:
        // no approved reviews means a clean 0 / 0, never a divide by zero and
        // never a stale average left behind by the last approved review.
        return [$count, $count > 0 ? round((float) $row->rating_mean, 2) : 0.0];
    }
}
