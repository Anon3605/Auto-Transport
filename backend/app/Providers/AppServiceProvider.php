<?php

namespace App\Providers;

use App\Listeners\ClaimGuestQuoteRequests;
use App\Models\Review;
use App\Observers\ReviewObserver;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Keeps services.rating_avg / rating_count in step with approved reviews.
        // Registered here rather than as an attribute on the model so the rebuild
        // is visible in one place when a "why did the rating move" question lands.
        Review::observe(ReviewObserver::class);

        Event::listen(Verified::class, ClaimGuestQuoteRequests::class);

        // One definition of "strong enough", referenced as Password::defaults() by
        // every FormRequest. Production demands the full set; locally the bar stays
        // low so seeded demo credentials and factory passwords remain usable.
        Password::defaults(function (): Password {
            $rule = Password::min(8);

            return $this->app->isProduction()
                ? $rule->min(12)->mixedCase()->numbers()->symbols()->uncompromised()
                : $rule;
        });
    }
}
