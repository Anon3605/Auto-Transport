<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\DriverProfile;
use App\Models\Service;
use App\Services\RatingAggregator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * The repair path design doc §4.7 demands in as many words: "denormalisation
 * without a rebuild path is a bug waiting to be discovered by a customer."
 *
 * Safe to run at any time and as often as you like -- every subject is recomputed
 * from its approved reviews, so a drifted row is corrected and a correct row is
 * rewritten with the numbers it already had. Schedule it nightly; run it by hand
 * after any bulk moderation, import or restore.
 */
class RebuildReviewAggregates extends Command
{
    protected $signature = 'reviews:rebuild-aggregates';

    protected $description = 'Recompute rating_avg / rating_count on services, carriers and driver_profiles from approved reviews';

    public function handle(RatingAggregator $aggregator): int
    {
        // withTrashed: a soft-deleted service or carrier can be restored, and a
        // rebuild that skipped it would restore a stale average with it.
        $bar = $this->output->createProgressBar(
            Service::query()->withTrashed()->count()
            + Carrier::query()->withTrashed()->count()
            + DriverProfile::query()->count()
        );

        $bar->start();

        $services = $this->rebuild(
            Service::query()->withTrashed(),
            fn (Service $service) => $aggregator->forService($service),
            $bar
        );

        $carriers = $this->rebuild(
            Carrier::query()->withTrashed(),
            fn (Carrier $carrier) => $aggregator->forCarrier($carrier),
            $bar
        );

        $drivers = $this->rebuild(
            DriverProfile::query(),
            fn (DriverProfile $driver) => $aggregator->forDriver($driver),
            $bar
        );

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Rebuilt %d service, %d carrier and %d driver rating aggregates.',
            $services,
            $carriers,
            $drivers
        ));

        return self::SUCCESS;
    }

    /**
     * chunkById rather than get(): this walks the whole catalog and the whole
     * driver roster, and the repair tool must not be the thing that runs the box
     * out of memory at 3am.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return int  rows recomputed
     */
    private function rebuild(Builder $query, callable $recompute, ProgressBar $bar): int
    {
        $handled = 0;

        $query->chunkById(200, function ($models) use ($recompute, $bar, &$handled): void {
            foreach ($models as $model) {
                $recompute($model);
                $bar->advance();
                $handled++;
            }
        });

        return $handled;
    }
}
