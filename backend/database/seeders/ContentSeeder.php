<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Faq;
use App\Models\Location;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * CMS defaults: the four system pages, site settings and the FAQ list
 * (design doc §6.5 and §6.6).
 *
 * Runs after CatalogSeeder because contact.map_lat / map_lng are taken from the
 * primary location rather than typed twice -- two copies of a coordinate is two
 * things to keep in step, and the map pin is the one that visibly goes wrong.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPages();
        $this->seedSettings();
        $this->seedFaqs();
    }

    /**
     * §6.5: is_system = true is what stops the admin panel deleting a page the
     * router still has a route for. It is not in Page::$fillable, so it is written
     * explicitly here -- a request body must never be able to set it.
     */
    private function seedPages(): void
    {
        // whereHas rather than Spatie's role() scope: that scope throws
        // RoleDoesNotExist, and this seeder must survive being run on its own.
        $authorId = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', UserRole::SuperAdmin->value))
            ->value('id');

        $pages = [
            [
                'slug' => 'home',
                'title' => 'Nationwide Car Shipping, Quoted in Sixty Seconds',
                'excerpt' => 'Door-to-door auto transport across all 48 contiguous states. Vetted, insured carriers, a real price up front, and a tracking link that actually tells you where your car is.',
                'template' => 'home',
                'meta_title' => 'Car Shipping & Auto Transport Quotes',
                'body' => <<<'HTML'
                    <h1>Ship your car with people who answer the phone</h1>
                    <p>Tell us where the vehicle is, where it needs to be and roughly when. You get a price in under a minute, a named dispatcher, and a shipment you can follow from your phone.</p>
                    <h2>How it works</h2>
                    <p><strong>1. Get your quote.</strong> Two addresses, one date window and the vehicle. No account, no call centre, no drip campaign.</p>
                    <p><strong>2. We assign a carrier.</strong> Every driver on our network is checked for active FMCSA authority and current cargo insurance before they are allowed near your car.</p>
                    <p><strong>3. Track it door to door.</strong> Pickup and delivery are photographed and signed. Status changes land on your phone as they happen, not the day after.</p>
                    <h2>Why customers stay</h2>
                    <p>The price we quote is the price you pay. No dispatch surcharge appearing at pickup, no fuel adjustment invented in transit. A deposit holds the slot and the balance is due on delivery.</p>
                    HTML,
            ],
            [
                'slug' => 'about',
                'title' => 'About Us',
                'excerpt' => 'A licensed auto transport broker running a vetted carrier network since 2014, with dispatchers who own their loads end to end.',
                'template' => 'default',
                'meta_title' => 'About Our Auto Transport Company',
                'body' => <<<'HTML'
                    <h1>We move about 9,000 vehicles a year</h1>
                    <p>We started in 2014 with one dispatcher and a spreadsheet. The spreadsheet is gone; the habit that came with it -- one person owning your shipment from quote to delivery -- is still how the company runs.</p>
                    <h2>Broker, and we say so</h2>
                    <p>We are a licensed and bonded transport broker. We do not own the trucks; we vet, contract and dispatch the carriers who do. That is the honest version, and it is why we can cover 48 states with the right trailer for your vehicle instead of the only trailer we happen to own.</p>
                    <h2>How a carrier gets on our network</h2>
                    <p>Active FMCSA operating authority, a satisfactory safety rating, and a cargo policy we verify directly with the insurer rather than accepting a forwarded PDF. Insurance expiry dates are tracked in our system and a carrier whose cover lapses stops receiving loads that day.</p>
                    <h2>What we will not do</h2>
                    <p>We will not quote a number we cannot dispatch to get your booking and then call back for more. A lowball quote is not a saving, it is a car sitting on a driveway for two weeks while nobody accepts the load.</p>
                    HTML,
            ],
            [
                'slug' => 'services',
                'title' => 'Our Services',
                'excerpt' => 'Open and enclosed carriers, door-to-door and terminal delivery, expedited pickup, motorcycles and heavy equipment.',
                'template' => 'services',
                'meta_title' => 'Auto Transport Services',
                'body' => <<<'HTML'
                    <h1>Every kind of vehicle, every kind of lane</h1>
                    <p>Most people need open transport, door to door, on a flexible date. If that is you, start there -- it is the cheapest thing we sell and it is what nine in ten of our shipments use.</p>
                    <h2>Choosing a trailer</h2>
                    <p>Open transport is the default. Choose enclosed when the paint is part of the value, when the car sits low enough to scrape a ramp, or when it is going to a show or an auction where arrival condition decides the price.</p>
                    <h2>Choosing a handover</h2>
                    <p>Door-to-door puts the truck as close to your address as it can legally get. Terminal-to-terminal costs less and gives you a drop-off window instead of a delivery window, at the price of a ride home from the terminal.</p>
                    <h2>When the date is not negotiable</h2>
                    <p>Expedited service guarantees pickup inside 24 to 48 hours with priority dispatch. It exists for military orders, job start dates and cars that were sold on Friday.</p>
                    HTML,
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact Us',
                'excerpt' => 'Talk to a dispatcher seven days a week, or send a message and get a written answer the same business day.',
                'template' => 'contact',
                'meta_title' => 'Contact Our Dispatch Team',
                'body' => <<<'HTML'
                    <h1>Talk to a dispatcher</h1>
                    <p>Phones are answered by the people who dispatch the loads, not by a call centre reading a script. If your car is already in transit, have your booking number ready and we can tell you where the truck is.</p>
                    <h2>Hours</h2>
                    <p>Monday to Friday, 8am to 6pm Central. Saturdays 9am to 2pm. Shipments already on the road are covered by an on-call dispatcher outside those hours.</p>
                    <h2>Written enquiries</h2>
                    <p>Use the form and you will have a written reply the same business day, usually within two hours. Include the pickup and delivery ZIP codes and the year, make and model, and the first reply can be an actual price instead of a request for more detail.</p>
                    HTML,
            ],
        ];

        foreach ($pages as $data) {
            $metaTitle = $data['meta_title'];
            unset($data['meta_title']);

            // withTrashed: a soft-deleted 'home' still holds the unique slug, and a
            // re-seed must resurrect it rather than fail on the index.
            $page = Page::withTrashed()->firstOrNew(['slug' => $data['slug']]);

            $page->fill($data);
            $page->deleted_at = null;
            $page->is_system = true;
            $page->status = Page::STATUS_PUBLISHED;
            $page->published_at ??= now();
            $page->author_id ??= $authorId;
            $page->save();

            $page->seo()->updateOrCreate([], [
                'meta_title' => Str::limit($metaTitle, 70, ''),
                'meta_description' => Str::limit((string) $page->excerpt, 160, ''),
                'og_title' => Str::limit($page->title, 95, ''),
                'og_description' => Str::limit((string) $page->excerpt, 200, ''),
                'sitemap_priority' => $page->slug === 'home' ? 1.0 : 0.7,
                'sitemap_changefreq' => 'weekly',
            ]);
        }
    }

    /**
     * §6.6. The contact.* and seo.* rows are is_public so GET /settings/public can
     * hand them to the mobile app unauthenticated; pricing.* stays private because
     * deposit percentage is a commercial term the server applies, not a client hint.
     */
    private function seedSettings(): void
    {
        $primary = Location::query()->primary()->first();

        $this->putDefault('contact', 'phone', '+1 (800) 555-0142', 'string', true, 'Public phone number');
        $this->putDefault('contact', 'email', 'support@autotransport.test', 'string', true, 'Public email address');

        // Stored as strings: Setting has no float type, and a decimal kept as text
        // survives the round trip without a binary-float rounding surprise.
        $this->putDefault('contact', 'map_lat', (string) ($primary?->lat ?? '32.7791000'), 'string', true, 'Contact map latitude');
        $this->putDefault('contact', 'map_lng', (string) ($primary?->lng ?? '-96.8008000'), 'string', true, 'Contact map longitude');
        $this->putDefault('contact', 'map_zoom', 13, 'int', true, 'Contact map zoom level');

        $this->putDefault('pricing', 'deposit_percent', 20, 'int', false, 'Deposit percentage taken at booking');
        $this->putDefault('pricing', 'quote_validity_days', 7, 'int', false, 'Days a quote stays valid');

        $this->putDefault('seo', 'default_title', 'Nationwide Car Shipping & Auto Transport', 'string', true, 'Default meta title');
        $this->putDefault('seo', 'default_description', 'Door-to-door car shipping across the lower 48 with vetted, insured carriers. Get an instant quote and track your vehicle from pickup to delivery.', 'string', true, 'Default meta description');
    }

    /**
     * Seeds a default without ever overwriting a live value: an operator who
     * changed the company phone number in the admin panel would not expect the next
     * deploy's db:seed to put the placeholder back. Metadata (type, visibility,
     * label) is code-owned and does get refreshed.
     */
    private function putDefault(string $group, string $key, string|int $value, string $type, bool $isPublic, string $label): void
    {
        $setting = Setting::query()->firstOrNew(['group' => $group, 'key' => $key]);

        if (! $setting->exists) {
            $setting->value = (string) $value;
        }

        $setting->type = $type;
        $setting->is_public = $isPublic;
        $setting->label = $label;
        $setting->save();
    }

    private function seedFaqs(): void
    {
        $faqs = [
            [
                'category' => 'Booking',
                'question' => 'How far in advance should I book?',
                'answer' => 'Two to three weeks is comfortable and usually gets you the best rate, because your load sits on the board long enough for a carrier already running that lane to pick it up. We routinely dispatch inside 48 hours when we have to -- that is what expedited service is for -- but planned bookings are cheaper.',
                'sort_order' => 10,
            ],
            [
                'category' => 'Booking',
                'question' => 'Do I need to be there for pickup and delivery?',
                'answer' => 'Someone aged 18 or over does. They hand over the keys, walk the vehicle with the driver and sign the bill of lading at both ends. It does not have to be you -- a friend, a relative, a neighbour or the dealership is fine, as long as we have their name and a phone number they will answer.',
                'sort_order' => 20,
            ],
            [
                'category' => 'Booking',
                'question' => 'Can I pack personal items in the car?',
                'answer' => 'Up to about 100 pounds in the trunk, below the window line, is generally accepted. Personal effects are not covered by the carrier cargo policy, so nothing valuable or irreplaceable. Anything loose in the cabin has to come out: three days of vibration turns a stray tool into a dented door card.',
                'sort_order' => 30,
            ],
            [
                'category' => 'Pricing',
                'question' => 'Is the quoted price the final price?',
                'answer' => 'Yes, as long as the details you gave us are accurate. Price moves only if the facts move -- an inoperable vehicle declared as running, a car that turns out to be modified or oversize, or a pickup address a truck genuinely cannot reach. In those cases we tell you the new number before dispatch, never after delivery.',
                'sort_order' => 10,
            ],
            [
                'category' => 'Pricing',
                'question' => 'When do I pay?',
                'answer' => 'A deposit confirms the booking and holds the carrier slot. The balance is due on delivery, before the keys change hands, by card or certified funds. Nothing is captured until the booking is actually confirmed.',
                'sort_order' => 20,
            ],
            [
                'category' => 'Pricing',
                'question' => 'Why is enclosed transport more expensive?',
                'answer' => 'An enclosed trailer carries two to six vehicles where an open trailer carries eight to ten, so the same fuel, tolls and driver hours are divided among far fewer cars. Expect 40 to 60 percent above the open rate. For a vehicle whose paint is part of its value, it is the cheapest insurance you will buy that month.',
                'sort_order' => 30,
            ],
            [
                'category' => 'Transport',
                'question' => 'How long will my car be in transit?',
                'answer' => 'Roughly 400 to 500 miles a day once the vehicle is loaded, plus a pickup window of one to five days depending on the lane. Coast to coast is typically 7 to 10 days door to door; a regional move is often 2 to 4. Your booking screen shows the scheduled dates and updates as the truck moves.',
                'sort_order' => 10,
            ],
            [
                'category' => 'Transport',
                'question' => 'Can you ship a car that does not run?',
                'answer' => 'Yes, provided it rolls, steers and brakes. Inoperable vehicles need a carrier with a winch, which is a smaller pool of trucks and about 35 percent more than a running car. Tell us at quote time -- a driver who arrives to find a vehicle that will not roll onto the trailer cannot load it, and that trip is billed as a dry run.',
                'sort_order' => 20,
            ],
            [
                'category' => 'Insurance',
                'question' => 'Is my vehicle insured while it is being transported?',
                'answer' => 'Every carrier on our network holds cargo insurance of at least $100,000, verified with the insurer directly and tracked against its expiry date in our system. Your own policy is not touched. The bill of lading photographed at pickup and delivery is the record that settles any condition dispute.',
                'sort_order' => 10,
            ],
            [
                'category' => 'Insurance',
                'question' => 'What happens if my car is damaged in transit?',
                'answer' => 'Note it on the bill of lading before you sign at delivery and photograph it there and then -- that document is the whole case. Call your dispatcher the same day and we open a claim with the carrier insurer on your behalf and stay on it until it closes. Damage in transit is rare; a signed clean delivery report with damage discovered a week later is very hard to recover.',
                'sort_order' => 20,
            ],
        ];

        foreach ($faqs as $faq) {
            Faq::query()->updateOrCreate(
                ['question' => $faq['question']],
                $faq + ['is_active' => true],
            );
        }
    }
}
