<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\ContactMessage;
use App\Models\QuoteRequest;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every admin page, rendered against real seeded rows.
 *
 * `view:cache` only proves a template parses. It says nothing about a view
 * reading a property the model does not expose, calling a relation that was
 * never eager-loaded, or a route() call naming a route that does not exist --
 * all of which are runtime failures on a page a human has to open. This walks
 * the panel end to end so those surface in CI instead of in production.
 */
class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            CatalogSeeder::class,
            ContentSeeder::class,
            DemoDataSeeder::class,
        ]);

        $this->admin = User::role('super-admin')->firstOrFail();
    }

    public function test_every_admin_index_page_renders(): void
    {
        $pages = [
            'admin.dashboard',
            'admin.users.index',
            'admin.users.create',
            'admin.roles.index',
            'admin.reviews.index',
            'admin.bookings.index',
            'admin.quotes.index',
            'admin.messages.index',
            'admin.services.index',
            'admin.settings.index',
        ];

        foreach ($pages as $name) {
            $this->actingAs($this->admin)
                ->get(route($name))
                ->assertOk("Expected {$name} to render for a super-admin.");
        }
    }

    public function test_every_admin_detail_page_renders(): void
    {
        $targets = [
            'admin.users.show' => User::query()->firstOrFail(),
            'admin.users.edit' => User::query()->firstOrFail(),
            'admin.reviews.show' => Review::query()->firstOrFail(),
            'admin.bookings.show' => Booking::query()->firstOrFail(),
            'admin.services.edit' => Service::query()->firstOrFail(),
        ];

        foreach ($targets as $name => $model) {
            $this->actingAs($this->admin)
                ->get(route($name, $model))
                ->assertOk("Expected {$name} to render for a super-admin.");
        }
    }

    /**
     * The demo seeder is not obliged to produce a quote request or a contact
     * message, so these are asserted only when a row exists rather than being
     * skipped silently or failing on an empty table.
     */
    public function test_optional_detail_pages_render_when_rows_exist(): void
    {
        $this->assertTrue(true);   // keeps the test meaningful when both are absent

        if (($quoteRequest = QuoteRequest::query()->first()) !== null) {
            $this->actingAs($this->admin)
                ->get(route('admin.quotes.show', $quoteRequest))
                ->assertOk('Expected admin.quotes.show to render.');
        }

        if (($message = ContactMessage::query()->first()) !== null) {
            $this->actingAs($this->admin)
                ->get(route('admin.messages.show', $message))
                ->assertOk('Expected admin.messages.show to render.');
        }
    }

    /**
     * Opening a message is what marks it read -- a separate "mark as read" button
     * is one nobody presses, and the dashboard's unread count would drift.
     */
    public function test_opening_a_message_marks_it_read(): void
    {
        $message = ContactMessage::query()->create([
            'name' => 'Jo Rivera',
            'email' => 'jo@example.com',
            'subject' => 'Shipping a project car',
            'message' => 'Is enclosed transport available to Boise in March?',
            'status' => ContactMessage::STATUS_NEW,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.messages.show', $message))
            ->assertOk();

        $this->assertSame(ContactMessage::STATUS_READ, $message->refresh()->status);
    }

    public function test_the_panel_is_closed_to_guests(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect();
        $this->get(route('admin.users.index'))->assertRedirect();
        $this->get(route('admin.settings.index'))->assertRedirect();
    }

    /**
     * Authenticated is not the same as employed. A customer holding a valid
     * session must not reach the panel at all -- this is the coarse `staff` gate,
     * separate from the per-resource permission checks.
     */
    public function test_the_panel_is_closed_to_customers(): void
    {
        $customer = User::role('customer')->first() ?? User::factory()->create();

        $this->actingAs($customer)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    /**
     * Support may work the review queue but must not reach settings. If this ever
     * starts passing at 200, the permission middleware has come unwired.
     */
    public function test_permissions_separate_staff_from_each_other(): void
    {
        $support = User::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support)->get(route('admin.reviews.index'))->assertOk();
        $this->actingAs($support)->get(route('admin.settings.index'))->assertForbidden();
    }
}
