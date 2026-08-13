<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * What the company sells, what can be shipped, and where it operates
 * (design doc §6.3, §6.4 and §6.7).
 *
 * Everything is matched on `slug`, which is the natural key the public URLs and
 * the mobile app already address these rows by -- so a re-seed refreshes copy and
 * pricing in place instead of creating a second "Open Carrier".
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVehicleTypes();
        $this->seedServices();
        $this->seedLocations();
    }

    /**
     * §6.3: these multipliers are the starting point of the pricing engine --
     * price × Σ(multiplier) per the estimator in §7. A sedan is the unit of 1.0
     * and everything else is expressed relative to a sedan's slot on a trailer.
     */
    private function seedVehicleTypes(): void
    {
        $types = [
            ['name' => 'Sedan',          'slug' => 'sedan',          'size_class' => 'standard', 'price_multiplier' => 1.000, 'default_length_in' => 192, 'default_weight_lb' => 3300, 'icon' => 'car-outline',        'sort_order' => 10],
            ['name' => 'Coupe',          'slug' => 'coupe',          'size_class' => 'compact',  'price_multiplier' => 1.000, 'default_length_in' => 183, 'default_weight_lb' => 3100, 'icon' => 'car-sport-outline', 'sort_order' => 20],
            ['name' => 'SUV',            'slug' => 'suv',            'size_class' => 'large',    'price_multiplier' => 1.150, 'default_length_in' => 196, 'default_weight_lb' => 4500, 'icon' => 'car-outline',        'sort_order' => 30],
            ['name' => 'Pickup Truck',   'slug' => 'pickup-truck',   'size_class' => 'large',    'price_multiplier' => 1.200, 'default_length_in' => 231, 'default_weight_lb' => 5000, 'icon' => 'car-outline',        'sort_order' => 40],
            ['name' => 'Minivan',        'slug' => 'minivan',        'size_class' => 'large',    'price_multiplier' => 1.150, 'default_length_in' => 204, 'default_weight_lb' => 4400, 'icon' => 'car-outline',        'sort_order' => 50],
            ['name' => 'Motorcycle',     'slug' => 'motorcycle',     'size_class' => 'moto',     'price_multiplier' => 0.600, 'default_length_in' => 87,  'default_weight_lb' => 500,  'icon' => 'bicycle-outline',   'sort_order' => 60],
            ['name' => 'Heavy/Oversize', 'slug' => 'heavy-oversize', 'size_class' => 'oversize', 'price_multiplier' => 1.800, 'default_length_in' => 264, 'default_weight_lb' => 10000, 'icon' => 'bus-outline',       'sort_order' => 70],
        ];

        foreach ($types as $type) {
            VehicleType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                $type + ['is_active' => true],
            );
        }
    }

    /**
     * §6.4. Categories exist so the services listing groups into the three
     * questions a customer actually asks: what kind of trailer, how is it handed
     * over, and do you handle my unusual thing.
     */
    private function seedServices(): void
    {
        $categories = [
            [
                'slug' => 'transport-methods',
                'name' => 'Transport Methods',
                'description' => 'How your vehicle rides: open air on a multi-car trailer, or sealed inside an enclosed rig.',
                'icon' => 'car-outline',
                'sort_order' => 10,
            ],
            [
                'slug' => 'delivery-options',
                'name' => 'Delivery Options',
                'description' => 'Where the handover happens and how fast it needs to be.',
                'icon' => 'navigate-outline',
                'sort_order' => 20,
            ],
            [
                'slug' => 'specialty-transport',
                'name' => 'Specialty Transport',
                'description' => 'Motorcycles, machinery and anything that will not sit on a standard car trailer.',
                'icon' => 'construct-outline',
                'sort_order' => 30,
            ],
        ];

        $categoryIds = [];

        foreach ($categories as $category) {
            $categoryIds[$category['slug']] = ServiceCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true],
            )->id;
        }

        // Pricing is realistic US brokerage economics in integer minor units (§4.4):
        // base covers pickup, paperwork and insurance overhead; the per-mile rate is
        // the line-haul; min_price is the floor that keeps a 40-mile move profitable.
        $services = [
            [
                'category' => 'transport-methods',
                'slug' => 'open-carrier-auto-transport',
                'name' => 'Open Carrier Transport',
                'short_description' => 'The way most cars ship: an eight to ten car open trailer, the best rates on the road, and the widest choice of pickup dates.',
                'base_price_cents' => 19500,
                'price_per_mile_cents' => 62,
                'min_price_cents' => 39500,
                'transit_days_min' => 3,
                'transit_days_max' => 7,
                'icon' => 'car-outline',
                'is_featured' => true,
                'sort_order' => 10,
                'description' => <<<'HTML'
                    <p>Open transport is the standard of the industry and around nine in ten vehicles we move travel this way. Your car rides on a multi-level trailer alongside seven to nine others, which is exactly why it costs the least: the carrier's fuel, tolls and driver hours are split across a full load.</p>
                    <h2>What to expect</h2>
                    <p>Your vehicle is exposed to weather and road dust in the same way it would be on a drive across the country, and nothing else. Every carrier on our network runs at least $100,000 in cargo insurance, and the condition of your car is photographed and signed off on the bill of lading at both pickup and delivery.</p>
                    <h2>Who it suits</h2>
                    <p>Daily drivers, dealer stock, lease returns, cars bought at auction, and anyone moving house who would rather not add two thousand miles to the odometer.</p>
                    HTML,
            ],
            [
                'category' => 'transport-methods',
                'slug' => 'enclosed-auto-transport',
                'name' => 'Enclosed Carrier Transport',
                'short_description' => 'A sealed trailer, hydraulic lift gate and soft tie-downs for classics, exotics and anything you would not park outside overnight.',
                'base_price_cents' => 29500,
                'price_per_mile_cents' => 105,
                'min_price_cents' => 69500,
                'transit_days_min' => 4,
                'transit_days_max' => 9,
                'icon' => 'cube-outline',
                'is_featured' => true,
                'sort_order' => 20,
                'description' => <<<'HTML'
                    <p>Enclosed transport puts four walls and a roof between your vehicle and the road. No weather, no stone chips, no eyes on it at a truck stop. It typically runs 40 to 60 percent above open transport, and for a car whose paint is a meaningful part of its value that is cheap insurance.</p>
                    <h2>Built for low clearance</h2>
                    <p>Our enclosed rigs load over a hydraulic lift gate rather than a ramp, so a splitter or an air-suspension car at ride height goes on without scraping. Vehicles are secured with soft nylon straps over the tyres, never chained by the suspension.</p>
                    <h2>Who it suits</h2>
                    <p>Collector and classic cars, exotics and supercars, show vehicles, motorcycles that need climate protection, and any car heading to auction where arrival condition is the whole point.</p>
                    HTML,
            ],
            [
                'category' => 'delivery-options',
                'slug' => 'door-to-door-transport',
                'name' => 'Door-to-Door Delivery',
                'short_description' => 'We collect from your driveway and deliver to theirs. No terminal detour, no second trip, no borrowed car to get home in.',
                'base_price_cents' => 22500,
                'price_per_mile_cents' => 68,
                'min_price_cents' => 45000,
                'transit_days_min' => 3,
                'transit_days_max' => 8,
                'icon' => 'home-outline',
                'sort_order' => 30,
                'description' => <<<'HTML'
                    <p>Door-to-door is the default on almost every booking we take, because the alternative costs you a day. The driver meets you at your address, or as close to it as an 80-foot rig can legally and safely get.</p>
                    <h2>The practical caveat</h2>
                    <p>Narrow residential streets, low branches, gated communities and HOA rules sometimes make the exact address impossible. When that happens the driver arranges a nearby meeting point you agree to in advance -- a supermarket or retail car park within a few minutes' drive -- and calls you an hour out.</p>
                    <h2>What we need from you</h2>
                    <p>Someone at least 18 years old at both ends to hand over keys and sign the bill of lading, and a phone number that will actually be answered while the truck is en route.</p>
                    HTML,
            ],
            [
                'category' => 'delivery-options',
                'slug' => 'terminal-to-terminal-transport',
                'name' => 'Terminal-to-Terminal',
                'short_description' => 'Drop off and collect at a secured terminal on your own schedule. The cheapest way to ship a car if the dates matter more than the doorstep.',
                'base_price_cents' => 15000,
                'price_per_mile_cents' => 55,
                'min_price_cents' => 34500,
                'transit_days_min' => 4,
                'transit_days_max' => 10,
                'icon' => 'business-outline',
                'sort_order' => 40,
                'description' => <<<'HTML'
                    <p>You deliver the vehicle to a secured, fenced terminal and collect it from another. Because the carrier loads a full trailer in one stop instead of threading through six suburbs, the saving comes back to you in the rate.</p>
                    <h2>How it works</h2>
                    <p>Terminals are open on weekdays and Saturday mornings, and the first five days of storage are included at each end. Drop off whenever it suits you inside that window; we will not ask you to wait at home for a four-hour delivery slot.</p>
                    <h2>Worth knowing</h2>
                    <p>Terminal-to-terminal usually adds a day or two of total transit versus door-to-door, and you will need a ride home from the terminal. If those two things are fine, this is the lowest price we can honestly quote.</p>
                    HTML,
            ],
            [
                'category' => 'delivery-options',
                'slug' => 'expedited-auto-transport',
                'name' => 'Expedited Transport',
                'short_description' => 'Guaranteed pickup inside 24 to 48 hours with priority dispatch, for relocations and sales that will not wait for a standard slot.',
                'base_price_cents' => 39500,
                'price_per_mile_cents' => 125,
                'min_price_cents' => 89500,
                'transit_days_min' => 1,
                'transit_days_max' => 3,
                'icon' => 'flash-outline',
                'is_featured' => true,
                'sort_order' => 50,
                'description' => <<<'HTML'
                    <p>Expedited buys you the top of the dispatch queue. Your load is posted at a premium rate that carriers accept immediately, and in most metropolitan lanes a truck is at your address within 24 to 48 hours of booking.</p>
                    <h2>What the premium pays for</h2>
                    <p>A driver reroutes for you, or runs with an empty slot they would otherwise have filled. On long hauls we can assign a two-driver team so the truck keeps moving through the night instead of stopping for a mandated rest.</p>
                    <h2>Who it suits</h2>
                    <p>Military PCS orders, a job start date that moved, a car sold on a Friday that has to be with the buyer by Monday, and dealer trades that are holding up a sale.</p>
                    HTML,
            ],
            [
                'category' => 'specialty-transport',
                'slug' => 'motorcycle-transport',
                'name' => 'Motorcycle Transport',
                'short_description' => 'Wheel chocks, soft straps and a dedicated crate or cradle. Your bike is never laid down, never ridden and never loaded loose with cars.',
                'base_price_cents' => 17500,
                'price_per_mile_cents' => 48,
                'min_price_cents' => 29500,
                'transit_days_min' => 3,
                'transit_days_max' => 8,
                'icon' => 'bicycle-outline',
                'sort_order' => 60,
                'description' => <<<'HTML'
                    <p>Bikes are secured upright in a wheel chock and strapped by the frame with soft ties, so nothing loads the fork seals or the suspension for a thousand miles. On enclosed runs the bike travels in its own cradle, separated from the vehicles around it.</p>
                    <h2>Before pickup</h2>
                    <p>Fuel down to a quarter tank, disable the alarm, fold the mirrors and tell us about any aftermarket fairing or luggage that changes the footprint. Loose items in a top box have to come off -- vibration over three days will find every unsecured thing on the bike.</p>
                    <h2>Who it suits</h2>
                    <p>Cross-country moves, track and rally weekends, dealer transfers, and any private sale where the buyer is not going to ride it 900 miles home.</p>
                    HTML,
            ],
            [
                'category' => 'specialty-transport',
                'slug' => 'heavy-equipment-transport',
                'name' => 'Heavy Equipment Transport',
                'short_description' => 'Step-deck and lowboy trailers for tractors, excavators, RVs and box trucks, including permits and route survey for oversize loads.',
                'base_price_cents' => 65000,
                'price_per_mile_cents' => 210,
                'min_price_cents' => 195000,
                'transit_days_min' => 5,
                'transit_days_max' => 14,
                'icon' => 'construct-outline',
                'sort_order' => 70,
                'description' => <<<'HTML'
                    <p>Anything that will not sit on a standard car trailer moves on a step-deck, lowboy or RGN behind a specialist operator. Farm tractors, mini excavators, skid steers, motorhomes, box trucks and industrial plant.</p>
                    <h2>Permits and routing</h2>
                    <p>Over legal width, height or weight, the load needs state permits and sometimes a surveyed route or an escort. We handle the filings and build the lead time into your quote rather than discovering it at a weigh station.</p>
                    <h2>What we need up front</h2>
                    <p>Real dimensions and operating weight, whether the machine drives on and off under its own power, attachment inventory, and honest access notes for both the loading and unloading site. A quote built on guessed numbers gets rewritten at pickup, and nobody enjoys that conversation.</p>
                    HTML,
            ],
        ];

        foreach ($services as $service) {
            $category = $service['category'];
            unset($service['category']);

            $model = Service::query()->updateOrCreate(
                ['slug' => $service['slug']],
                $service + [
                    'service_category_id' => $categoryIds[$category],
                    'currency' => 'USD',
                    'is_featured' => false,
                    'is_active' => true,
                ],
            );

            // §4.9: one polymorphic seo_meta row per service page. Lengths mirror the
            // column widths, which mirror where Google truncates a SERP entry.
            $model->seo()->updateOrCreate([], [
                'meta_title' => Str::limit($model->name.' | Nationwide Car Shipping', 70, ''),
                'meta_description' => Str::limit((string) $model->short_description, 160, ''),
                'og_title' => Str::limit($model->name, 95, ''),
                'og_description' => Str::limit((string) $model->short_description, 200, ''),
                'sitemap_priority' => 0.8,
                'sitemap_changefreq' => 'monthly',
            ]);
        }
    }

    /**
     * §6.7: the primary row is what the Contact page map embed centres on, so its
     * lat/lng are real coordinates and not placeholders -- a zeroed pair drops the
     * pin in the Gulf of Guinea, which is a bug report waiting to happen.
     */
    private function seedLocations(): void
    {
        $weekday = ['08:00', '18:00'];

        $locations = [
            [
                'slug' => 'dallas-headquarters',
                'name' => 'Dallas Headquarters',
                'type' => 'office',
                'line1' => '1200 Main Street',
                'line2' => 'Suite 400',
                'city' => 'Dallas',
                'state' => 'TX',
                'postal_code' => '75202',
                'lat' => 32.7791000,
                'lng' => -96.8008000,
                'phone' => '+18005550142',
                'email' => 'dispatch@autotransport.test',
                'is_primary' => true,
                'hours' => [
                    'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday,
                    'thu' => $weekday, 'fri' => $weekday,
                    'sat' => ['09:00', '14:00'],
                    'sun' => null,
                ],
            ],
            [
                'slug' => 'los-angeles-terminal',
                'name' => 'Los Angeles Terminal',
                'type' => 'terminal',
                'line1' => '2450 East Washington Boulevard',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90021',
                'lat' => 34.0225000,
                'lng' => -118.2277000,
                'phone' => '+18005550188',
                'hours' => [
                    'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday,
                    'thu' => $weekday, 'fri' => $weekday,
                    'sat' => ['09:00', '13:00'],
                    'sun' => null,
                ],
            ],
            [
                'slug' => 'chicago-terminal',
                'name' => 'Chicago Terminal',
                'type' => 'terminal',
                'line1' => '4700 South Kildare Avenue',
                'city' => 'Chicago',
                'state' => 'IL',
                'postal_code' => '60632',
                'lat' => 41.8069000,
                'lng' => -87.7328000,
                'phone' => '+18005550193',
                'hours' => [
                    'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday,
                    'thu' => $weekday, 'fri' => $weekday,
                    'sat' => ['09:00', '13:00'],
                    'sun' => null,
                ],
            ],
        ];

        foreach ($locations as $location) {
            Location::query()->updateOrCreate(
                ['slug' => $location['slug']],
                $location + [
                    'country_code' => 'US',
                    'is_primary' => false,
                    'is_active' => true,
                ],
            );
        }
    }
}
