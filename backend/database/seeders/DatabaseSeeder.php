<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * NOTE: this class deliberately does NOT use WithoutModelEvents, which the
     * Laravel stub ships with by default.
     *
     * That trait suppresses Eloquent's creating/created events for the whole
     * seed run, and HasUlid populates `ulid` from the creating hook. With the
     * trait on, every seeded row fails the NOT NULL constraint on `ulid` --
     * and where a column happens to be nullable it would insert silently blank
     * instead, which is worse. ReviewObserver's rating-aggregate maintenance
     * hangs off the same events.
     *
     * If a seeder ever does need events off, scope it to that one seeder rather
     * than reinstating the trait here.
     *
     * Order is forced by foreign keys and by role lookups:
     *   roles/permissions + super-admin  ->  catalog  ->  content  ->  demo rows
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,   // §6.1-6.2  roles, permissions, super-admin
            CatalogSeeder::class,          // §6.3-6.4  vehicle types, categories, services
            ContentSeeder::class,          // §6.5-6.7  system pages, settings, primary location, FAQs
        ]);

        // Sample customers, quote requests, bookings and reviews. Useful for
        // clicking through the admin panel and for the RN app to render against;
        // skipped in production so a live database never gets fixture data.
        if (! app()->environment('production')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
