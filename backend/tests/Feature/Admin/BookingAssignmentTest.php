<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Carrier;
use App\Models\DriverProfile;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Carrier $carrier;

    private User $driver;

    private Truck $truck;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, CatalogSeeder::class, ContentSeeder::class]);

        $this->admin = User::role('super-admin')->firstOrFail();

        $this->carrier = Carrier::query()->create([
            'company_name' => 'Lone Star Auto Logistics',
            'status' => 'active',
        ]);

        $this->driver = User::factory()->create(['status' => 'active']);
        $this->driver->assignRole('driver');

        DriverProfile::query()->create([
            'user_id' => $this->driver->id,
            'carrier_id' => $this->carrier->id,
        ]);

        $this->truck = Truck::query()->create([
            'carrier_id' => $this->carrier->id,
            'unit_number' => 'T-101',
            'trailer_type' => 'open',
            'is_active' => true,
        ]);

        $this->booking = Booking::factory()
            ->for(User::factory()->create(['status' => 'active']))
            ->create(['status' => BookingStatus::Confirmed]);
    }

    /** The reported crash: submitting the assignment form 500s. */
    public function test_assigning_a_driver_does_not_crash(): void
    {
        $response = $this->actingAs($this->admin)->post(
            route('admin.bookings.assign', $this->booking),
            [
                'carrier_id' => $this->carrier->id,
                'driver_id' => $this->driver->id,
                'truck_id' => $this->truck->id,
            ]
        );

        // Anything in the 500s means the request blew up rather than validated.
        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'Assigning a driver returned a server error.'
        );

        $response->assertRedirect(route('admin.bookings.show', $this->booking));

        $this->booking->refresh();
        $this->assertSame($this->carrier->id, $this->booking->carrier_id);
        $this->assertSame($this->driver->id, $this->booking->driver_id);
        $this->assertSame($this->truck->id, $this->booking->truck_id);
    }

    /** §4.8: the assignment leaves an internal trail, not a customer notification. */
    public function test_assignment_records_an_internal_timeline_event(): void
    {
        $this->actingAs($this->admin)->post(route('admin.bookings.assign', $this->booking), [
            'carrier_id' => $this->carrier->id,
            'driver_id' => $this->driver->id,
        ]);

        $event = $this->booking->events()->where('event_type', 'assignment_changed')->firstOrFail();

        $this->assertFalse((bool) $event->is_customer_visible);
    }

    /** Clearing the fields un-assigns, rather than being rejected as empty. */
    public function test_assignment_can_be_cleared(): void
    {
        $this->booking->forceFill([
            'carrier_id' => $this->carrier->id,
            'driver_id' => $this->driver->id,
        ])->save();

        $this->actingAs($this->admin)->post(route('admin.bookings.assign', $this->booking), [
            'carrier_id' => '',
            'driver_id' => '',
            'truck_id' => '',
        ])->assertRedirect();

        $this->booking->refresh();
        $this->assertNull($this->booking->carrier_id);
        $this->assertNull($this->booking->driver_id);
    }

    /** A driver who does not work for the chosen carrier is refused. */
    public function test_a_driver_from_another_carrier_is_rejected(): void
    {
        $otherCarrier = Carrier::query()->create(['company_name' => 'Rival Haulage', 'status' => 'active']);

        $this->actingAs($this->admin)->post(route('admin.bookings.assign', $this->booking), [
            'carrier_id' => $otherCarrier->id,
            'driver_id' => $this->driver->id,
        ])->assertSessionHasErrors('driver_id');

        $this->assertNull($this->booking->refresh()->driver_id);
    }

    /**
     * A truck from another carrier is refused. This is the mistake that otherwise
     * surfaces when a driver arrives at a pickup in the wrong trailer.
     */
    public function test_a_truck_from_another_carrier_is_rejected(): void
    {
        $otherCarrier = Carrier::query()->create(['company_name' => 'Rival Haulage', 'status' => 'active']);
        $otherTruck = Truck::query()->create([
            'carrier_id' => $otherCarrier->id,
            'unit_number' => 'R-9',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('admin.bookings.assign', $this->booking), [
            'carrier_id' => $this->carrier->id,
            'truck_id' => $otherTruck->id,
        ])->assertSessionHasErrors('truck_id');
    }

    /** Assigning somebody who is not a driver at all must not succeed. */
    public function test_a_non_driver_cannot_be_assigned(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $customer->assignRole('customer');

        $this->actingAs($this->admin)->post(route('admin.bookings.assign', $this->booking), [
            'carrier_id' => $this->carrier->id,
            'driver_id' => $customer->id,
        ])->assertSessionHasErrors('driver_id');

        $this->assertNull($this->booking->refresh()->driver_id);
    }

    /** Support may read a booking but must not reassign it. */
    public function test_a_user_without_manage_bookings_cannot_assign(): void
    {
        $support = User::factory()->create(['status' => 'active']);
        $support->assignRole('support');

        $this->actingAs($support)->post(route('admin.bookings.assign', $this->booking), [
            'carrier_id' => $this->carrier->id,
        ])->assertForbidden();
    }

    /**
     * The page returning 200 is not enough: a @can that quietly evaluates false
     * would leave the operator on a working page with no way to do the thing.
     * These assert the controls are actually rendered.
     */
    public function test_the_assignment_and_payment_forms_are_rendered(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertOk();

        $response->assertSee(route('admin.bookings.assign', $this->booking));
        $response->assertSee(route('admin.bookings.payments.store', $this->booking));
        $response->assertSee('Record a payment');
        $response->assertSee('Save assignment');
    }
}
