<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Carrier;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The driver surface.
 *
 * These tests exist because the bug they cover was invisible: a driver hitting the
 * customer endpoint got a valid 200 with an empty list, which reads as "no work
 * today" rather than "wrong endpoint entirely".
 */
class DriverJobTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Carrier $carrier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, CatalogSeeder::class, ContentSeeder::class]);

        $this->driver = User::factory()->create(['status' => 'active']);
        $this->driver->assignRole('driver');

        $this->carrier = Carrier::query()->create([
            'company_name' => 'Lone Star Auto Logistics',
            'status' => 'active',
        ]);
    }

    public function test_a_driver_sees_jobs_assigned_to_them_not_ones_they_own(): void
    {
        $customer = User::factory()->create(['status' => 'active']);

        // Assigned to the driver, owned by the customer. This is the real shape.
        $assigned = $this->booking($customer, [
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Assigned,
        ]);

        // Owned by the driver personally: must NOT appear on the job list.
        $ownedByDriver = $this->booking($this->driver, ['status' => BookingStatus::Confirmed]);

        Sanctum::actingAs($this->driver);

        $ulids = collect($this->getJson('/api/v1/driver/jobs')->assertOk()->json('data'))
            ->pluck('ulid')
            ->all();

        $this->assertContains($assigned->ulid, $ulids);
        $this->assertNotContains(
            $ownedByDriver->ulid,
            $ulids,
            'The job list is showing shipments the driver bought, not work assigned to them.'
        );
    }

    /** The exact bug reported: the customer endpoint is useless to a driver. */
    public function test_the_customer_endpoint_does_not_show_a_driver_their_assigned_work(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $this->booking($customer, ['driver_id' => $this->driver->id, 'status' => BookingStatus::Assigned]);

        Sanctum::actingAs($this->driver);

        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_delivered_jobs_are_hidden_by_default_but_available_on_request(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $done = $this->booking($customer, [
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Delivered,
        ]);

        Sanctum::actingAs($this->driver);

        $this->getJson('/api/v1/driver/jobs')->assertOk()->assertJsonCount(0, 'data');

        $all = $this->getJson('/api/v1/driver/jobs?include=all')->assertOk();
        $all->assertJsonCount(1, 'data');
        $this->assertSame($done->ulid, $all->json('data.0.ulid'));
    }

    public function test_a_driver_can_report_progress_through_the_state_machine(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $job = $this->booking($customer, [
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Assigned,
        ]);

        Sanctum::actingAs($this->driver);

        $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", ['status' => 'picked_up'])
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up');

        $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", ['status' => 'in_transit'])->assertOk();

        $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        // §4.8: every status move leaves a timeline row behind it.
        $this->assertSame(3, $job->fresh()->events()->where('event_type', 'status_change')->count());
    }

    /** A location ping is recorded, and is internal rather than customer-facing. */
    public function test_a_location_ping_is_recorded_as_an_internal_event(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $job = $this->booking($customer, [
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Assigned,
        ]);

        Sanctum::actingAs($this->driver);

        $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", [
            'status' => 'picked_up',
            'lat' => 30.2672,
            'lng' => -97.7431,
        ])->assertOk();

        $ping = $job->fresh()->events()->where('event_type', 'location_ping')->firstOrFail();

        $this->assertFalse((bool) $ping->is_customer_visible);
        $this->assertSame('30.2672000', (string) $ping->lat);
    }

    /** Confirming is a commercial decision; cancelling has refund consequences. */
    public function test_a_driver_cannot_confirm_or_cancel(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $job = $this->booking($customer, [
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::PendingPayment,
        ]);

        Sanctum::actingAs($this->driver);

        foreach (['confirmed', 'cancelled', 'assigned'] as $forbidden) {
            $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", ['status' => $forbidden])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }
    }

    /** An illegal move is a stale screen in the cab, not a crash. */
    public function test_an_illegal_transition_is_a_422_not_a_500(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $job = $this->booking($customer, [
            'driver_id' => $this->driver->id,
            'status' => BookingStatus::Assigned,
        ]);

        Sanctum::actingAs($this->driver);

        // assigned -> delivered skips pickup and transit.
        $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", ['status' => 'delivered'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_driver_cannot_touch_a_job_belonging_to_another_driver(): void
    {
        $otherDriver = User::factory()->create(['status' => 'active']);
        $otherDriver->assignRole('driver');
        $customer = User::factory()->create(['status' => 'active']);

        $job = $this->booking($customer, [
            'driver_id' => $otherDriver->id,
            'status' => BookingStatus::Assigned,
        ]);

        Sanctum::actingAs($this->driver);

        // 404, not 403: the response must not confirm the row exists.
        $this->getJson("/api/v1/driver/jobs/{$job->ulid}")->assertNotFound();
        $this->postJson("/api/v1/driver/jobs/{$job->ulid}/status", ['status' => 'picked_up'])->assertNotFound();

        $this->assertSame(BookingStatus::Assigned, $job->fresh()->status);
    }

    /** A customer token must not reach the driver surface at all. */
    public function test_a_customer_cannot_use_the_driver_endpoints(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $customer->assignRole('customer');

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/driver/jobs')->assertForbidden();
    }

    public function test_a_guest_cannot_use_the_driver_endpoints(): void
    {
        $this->getJson('/api/v1/driver/jobs')->assertUnauthorized();
    }

    /** @param array<string, mixed> $attributes */
    private function booking(User $owner, array $attributes = []): Booking
    {
        return Booking::factory()->for($owner)->create(array_merge([
            'carrier_id' => $this->carrier->id,
        ], $attributes));
    }
}
