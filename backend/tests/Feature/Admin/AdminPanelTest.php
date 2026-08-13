<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the panel's load-bearing rules rather than its markup: who gets in, the
 * two guards that keep a super-admin from disappearing, and the two places where
 * a wrong answer is silent (a rating aggregate that stops moving, a status
 * transition that 500s instead of explaining itself).
 *
 * No model factories beyond UserFactory: the other tables have none yet, and
 * spelling out the NOT NULL columns keeps this file honest about the schema.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles and permissions first -- every route below is behind one, and
        // Spatie caches the map per process.
        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * The seeder also plants the first super-admin (§6.2). These tests count
         * super-admins, so the population starts empty and every account below is
         * explicit. model_has_roles goes first: it has no foreign key to users, and
         * SQLite reissues the vacated id -- an orphaned grant would silently make
         * the next account created here a super-admin.
         */
        DB::table('model_has_roles')->delete();
        User::query()->forceDelete();
    }

    public function test_a_customer_cannot_reach_the_admin_panel(): void
    {
        $customer = $this->user(UserRole::Customer);

        $response = $this->actingAs($customer)->get('/admin');

        // The staff gate throws AuthorizationException; whether that surfaces as
        // 403 or a redirect is the middleware's business. What this pins down is
        // that the dashboard never renders for a customer.
        $this->assertNotSame(200, $response->status());
    }

    public function test_a_guest_is_sent_to_the_admin_login_screen(): void
    {
        // The auth middleware only knows route('login'), which is why web.php
        // keeps that name pointed at the panel's form.
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get(route('login'))->assertRedirect(route('admin.login'));
    }

    public function test_a_super_admin_reaches_the_dashboard(): void
    {
        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_a_customer_cannot_sign_in_to_the_panel(): void
    {
        // The 'hashed' cast turns this into a hash on assignment.
        $customer = $this->user(UserRole::Customer, ['password' => 'secret-password-1']);

        $this->post(route('admin.login.attempt'), [
            'email' => $customer->email,
            'password' => 'secret-password-1',
        ])->assertSessionHasErrors('email');

        // The session must never have been opened, not even briefly.
        $this->assertGuest();
    }

    public function test_the_user_list_can_be_searched(): void
    {
        $this->user(UserRole::Customer, ['name' => 'Marguerite Delacroix', 'email' => 'marguerite@example.test']);
        $this->user(UserRole::Customer, ['name' => 'Bartholomew Quinn', 'email' => 'bartholomew@example.test']);

        $response = $this->actingAs($this->user(UserRole::SuperAdmin))
            ->get(route('admin.users.index', ['q' => 'Marguerite']));

        $response->assertOk()
            ->assertSee('marguerite@example.test')
            ->assertDontSee('bartholomew@example.test');
    }

    public function test_the_user_list_can_be_filtered_by_role(): void
    {
        $this->user(UserRole::Customer, ['name' => 'Celestine Ward', 'email' => 'celestine@example.test']);
        $this->user(UserRole::Dispatcher, ['name' => 'Ignatius Bell', 'email' => 'ignatius@example.test']);

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->get(route('admin.users.index', ['role' => UserRole::Dispatcher->value]))
            ->assertOk()
            ->assertSee('ignatius@example.test')
            ->assertDontSee('celestine@example.test');
    }

    public function test_the_last_super_admin_cannot_be_deleted(): void
    {
        $root = $this->user(UserRole::SuperAdmin);
        $admin = $this->user(UserRole::Admin);

        $this->assertSame(1, $this->superAdminCount());

        $this->actingAs($admin)
            ->from(route('admin.users.show', $root))
            ->delete(route('admin.users.destroy', $root))
            ->assertRedirect(route('admin.users.show', $root))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($root);
    }

    public function test_a_super_admin_cannot_delete_their_own_account(): void
    {
        $root = $this->user(UserRole::SuperAdmin);
        $second = $this->user(UserRole::SuperAdmin);

        // Two of them, so the last-super-admin guard is not what answers here.
        $this->assertSame(2, $this->superAdminCount());

        $this->actingAs($root)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $root))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($root);
        $this->assertNotSoftDeleted($second);
    }

    public function test_a_deletable_user_is_only_soft_deleted(): void
    {
        $customer = $this->user(UserRole::Customer);

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->delete(route('admin.users.destroy', $customer))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted($customer);
    }

    public function test_an_admin_cannot_grant_the_super_admin_role(): void
    {
        $admin = $this->user(UserRole::Admin);
        $target = $this->user(UserRole::Customer, ['name' => 'Perpetua Vance']);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $target))
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                UserRole::Customer->value,
                UserRole::SuperAdmin->value,
            ]))
            ->assertSessionHasErrors();

        $this->assertFalse($target->fresh()->hasRole(UserRole::SuperAdmin->value));
    }

    public function test_a_super_admin_can_grant_the_super_admin_role(): void
    {
        $root = $this->user(UserRole::SuperAdmin);
        $target = $this->user(UserRole::Admin, ['name' => 'Octavia Reyes']);

        $this->actingAs($root)
            ->put(route('admin.users.update', $target), $this->userPayload($target, [
                UserRole::SuperAdmin->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole(UserRole::SuperAdmin->value));
    }

    public function test_an_empty_password_field_leaves_the_existing_hash_alone(): void
    {
        $target = $this->user(UserRole::Customer, ['password' => 'secret-password-1']);
        $original = $target->password;

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->put(route('admin.users.update', $target), $this->userPayload($target, [UserRole::Customer->value], [
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($original, $target->fresh()->password);
    }

    public function test_approving_a_review_rebuilds_the_service_rating_aggregate(): void
    {
        $service = $this->service();
        $customer = $this->user(UserRole::Customer);
        $booking = $this->booking($customer, $service);

        $review = Review::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'rating_overall' => 5,
        ]);

        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertSame(0, (int) $service->fresh()->rating_count);

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->from(route('admin.reviews.index'))
            ->post(route('admin.reviews.approve', $review))
            ->assertSessionHas('status');

        $service->refresh();

        $this->assertSame(ReviewStatus::Approved, $review->fresh()->status);
        $this->assertSame(1, (int) $service->rating_count);
        $this->assertSame('5.00', (string) $service->rating_avg);
    }

    public function test_rejecting_a_review_requires_a_reason(): void
    {
        $service = $this->service();
        $customer = $this->user(UserRole::Customer);

        $review = Review::query()->create([
            'booking_id' => $this->booking($customer, $service)->id,
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'rating_overall' => 2,
        ]);

        $moderator = $this->user(UserRole::Support);

        $this->actingAs($moderator)
            ->from(route('admin.reviews.show', $review))
            ->post(route('admin.reviews.reject', $review), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);

        $this->actingAs($moderator)
            ->from(route('admin.reviews.show', $review))
            ->post(route('admin.reviews.reject', $review), ['reason' => 'Names a third party.'])
            ->assertSessionHas('status');

        $rejected = $review->fresh();

        $this->assertSame(ReviewStatus::Rejected, $rejected->status);
        $this->assertSame('Names a third party.', $rejected->rejection_reason);
        $this->assertSame($moderator->id, $rejected->moderated_by);
        // Still nothing on the public page, so the aggregate must not have moved.
        $this->assertSame(0, (int) $review->service->fresh()->rating_count);
    }

    public function test_an_illegal_booking_transition_flashes_an_error(): void
    {
        $booking = $this->booking(
            $this->user(UserRole::Customer),
            $this->service(),
            BookingStatus::PendingPayment,
        );

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->from(route('admin.bookings.show', $booking))
            ->post(route('admin.bookings.status', $booking), [
                // pending_payment may only go to confirmed or cancelled.
                'status' => BookingStatus::Delivered->value,
            ])
            ->assertRedirect(route('admin.bookings.show', $booking))
            ->assertSessionHas('error');

        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_events', 0);
    }

    public function test_a_legal_booking_transition_writes_a_timeline_event(): void
    {
        $booking = $this->booking(
            $this->user(UserRole::Customer),
            $this->service(),
            BookingStatus::PendingPayment,
        );

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->from(route('admin.bookings.show', $booking))
            ->post(route('admin.bookings.status', $booking), [
                'status' => BookingStatus::Confirmed->value,
                'description' => 'Deposit cleared.',
            ])
            ->assertSessionHas('status');

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $booking->id,
            'event_type' => 'status_change',
            'from_status' => BookingStatus::PendingPayment->value,
            'to_status' => BookingStatus::Confirmed->value,
            'description' => 'Deposit cleared.',
        ]);
    }

    public function test_a_support_agent_cannot_reach_settings_or_the_user_list(): void
    {
        $support = $this->user(UserRole::Support);

        // Support holds view_reviews and view_bookings but neither
        // manage_settings nor view_users.
        $this->actingAs($support)->get(route('admin.reviews.index'))->assertOk();
        $this->actingAs($support)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.users.index'))->assertForbidden();
    }

    // --- fixtures --------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(UserRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 'active']);
        $user->assignRole($role->value);

        return $user->fresh();
    }

    private function service(): Service
    {
        return Service::query()->create([
            'name' => 'Enclosed Auto Transport',
            'slug' => 'enclosed-auto-transport-'.uniqid(),
            'base_price_cents' => 95000,
            'price_per_mile_cents' => 85,
            'min_price_cents' => 45000,
            'currency' => 'USD',
        ]);
    }

    /** Delivered by default, because §4.7 allows a review on nothing else. */
    private function booking(
        User $customer,
        Service $service,
        BookingStatus $status = BookingStatus::Delivered,
    ): Booking {
        return Booking::query()->create([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'status' => $status,
            'pickup_line1' => '18 Harbour Row',
            'pickup_city' => 'Baltimore',
            'pickup_state' => 'MD',
            'dropoff_line1' => '4400 Alameda Ave',
            'dropoff_city' => 'El Paso',
            'dropoff_state' => 'TX',
            'total_price_cents' => 128000,
            'deposit_cents' => 25000,
            'currency' => 'USD',
        ]);
    }

    /**
     * A full edit payload -- UpdateUserRequest is not a PATCH, so every required
     * field has to be present or the assertion under test never runs.
     *
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(User $user, array $roles, array $overrides = []): array
    {
        return $overrides + [
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'active',
            'locale' => 'en',
            'timezone' => 'UTC',
            'roles' => $roles,
        ];
    }

    private function superAdminCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::SuperAdmin->value))
            ->count();
    }
}
