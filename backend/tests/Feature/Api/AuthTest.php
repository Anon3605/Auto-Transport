<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** The mobile client's paths are fixed by mobile/src/api/endpoints.ts. */
    private const REGISTER = '/api/v1/auth/register';

    private const LOGIN = '/api/v1/auth/login';

    private const LOGOUT = '/api/v1/auth/logout';

    private const ME = '/api/v1/auth/me';

    private const FORGOT = '/api/v1/auth/forgot-password';

    /** Not in endpoints.ts yet; kept here so a route rename is a one-line edit. */
    private const PROFILE = '/api/v1/profile';

    private const ADDRESSES = '/api/v1/profile/addresses';

    private const PASSWORD = 'correct-horse-42';

    // --- Registration ----------------------------------------------------

    public function test_register_returns_a_working_token_and_assigns_the_customer_role(): void
    {
        $response = $this->postJson(self::REGISTER, [
            'name' => 'Dana Reyes',
            // Mixed case on the way in: sqlite compares strings case-sensitively,
            // so the address has to be folded before it is stored.
            'email' => 'Dana@Example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'phone' => '+1 (555) 123-4567',
            'device_name' => 'Pixel 8',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['ulid', 'name', 'email', 'phone', 'avatar_url', 'roles', 'email_verified'],
            ])
            ->assertJsonPath('user.email', 'dana@example.com')
            ->assertJsonPath('user.roles', [UserRole::Customer->value])
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.avatar_url', null);

        $user = User::query()->where('email', 'dana@example.com')->sole();

        $this->assertTrue($user->hasRole(UserRole::Customer->value));
        $this->assertSame('+15551234567', $user->phone_normalized);
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame('Pixel 8', $user->tokens()->sole()->name);
        $this->assertSame($user->ulid, $response->json('user.ulid'));

        // The token in the body is the one the client will send back.
        $this->withToken($response->json('token'))
            ->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('user.ulid', $user->ulid);
    }

    public function test_register_fires_the_registered_event(): void
    {
        Event::fake([Registered::class]);

        $this->postJson(self::REGISTER, $this->registrationPayload())->assertCreated();

        Event::assertDispatched(Registered::class);
    }

    public function test_register_rejects_a_duplicate_email_whatever_its_case(): void
    {
        User::factory()->create(['email' => 'dana@example.com']);

        $this->postJson(self::REGISTER, $this->registrationPayload(['email' => 'DANA@example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::query()->where('email', 'dana@example.com')->count());
    }

    public function test_register_requires_a_confirmed_password_of_letters_and_numbers(): void
    {
        $this->postJson(self::REGISTER, [
            'name' => 'Dana Reyes',
            'email' => 'dana@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    // --- Login -----------------------------------------------------------

    public function test_login_returns_a_token_and_stamps_the_sign_in(): void
    {
        $user = $this->customer();

        $response = $this->postJson(self::LOGIN, [
            'email' => 'dana@example.com',
            'password' => self::PASSWORD,
            'device_name' => 'iPhone 15',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.ulid', $user->ulid)
            ->assertJsonPath('user.roles', []);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('iPhone 15', $user->tokens()->sole()->name);

        $user->refresh();
        $this->assertSame(0, (int) $user->failed_login_count);
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('127.0.0.1', $user->last_login_ip);
    }

    public function test_login_labels_the_token_mobile_when_no_device_name_is_sent(): void
    {
        $user = $this->customer();

        $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertSame('mobile', $user->tokens()->sole()->name);
    }

    public function test_login_fails_with_422_on_a_wrong_password(): void
    {
        $user = $this->customer();

        $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => 'wrong-password-42'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, (int) $user->refresh()->failed_login_count);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_does_not_reveal_whether_an_address_has_an_account(): void
    {
        $this->customer();

        $known = $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => 'wrong-password-42'])
            ->assertStatus(422);

        $unknown = $this->postJson(self::LOGIN, ['email' => 'ghost@example.com', 'password' => 'wrong-password-42'])
            ->assertStatus(422);

        $this->assertSame($known->json('errors.email'), $unknown->json('errors.email'));
    }

    public function test_account_locks_for_fifteen_minutes_after_five_failed_attempts(): void
    {
        $user = $this->customer();

        foreach (range(1, 4) as $attempt) {
            $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => 'wrong-password-42'])
                ->assertStatus(422);
        }

        // The fifth failure trips the lock and says so.
        $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => 'wrong-password-42'])
            ->assertStatus(423);

        $user->refresh();
        $this->assertTrue($user->isLocked());
        $this->assertSame(0, (int) $user->failed_login_count);

        // The right password is no help while the lock stands.
        $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => self::PASSWORD])
            ->assertStatus(423);
        $this->assertSame(0, $user->tokens()->count());

        // And it lifts by itself -- no support ticket required.
        $this->travel(16)->minutes();

        $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => self::PASSWORD])
            ->assertOk();
        $this->assertSame(1, $user->tokens()->count());

        $this->travelBack();
    }

    public function test_login_rejects_an_account_that_is_not_active(): void
    {
        $user = $this->customer(['status' => 'suspended']);

        $this->postJson(self::LOGIN, ['email' => 'dana@example.com', 'password' => self::PASSWORD])
            ->assertStatus(403);

        $this->assertSame(0, $user->tokens()->count());
    }

    // --- Session surface -------------------------------------------------

    public function test_me_requires_authentication(): void
    {
        $this->getJson(self::ME)->assertUnauthorized();
    }

    public function test_me_returns_the_client_user_shape(): void
    {
        $user = $this->customer();
        $user->assignRole(Role::findOrCreate(UserRole::Customer->value, 'web'));

        Sanctum::actingAs($user);

        $this->getJson(self::ME)
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'ulid' => $user->ulid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => null,
                    'roles' => [UserRole::Customer->value],
                    'email_verified' => true,
                ],
            ]);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->customer();

        $phone = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $this->withToken($phone)->postJson(self::LOGOUT)->assertNoContent();

        $this->assertSame(['tablet'], $user->tokens()->pluck('name')->all());

        // The AuthManager is a singleton that outlives a test request, and
        // RequestGuard memoises the user it resolved. Without dropping the
        // guards, the next two calls would replay the first request's identity
        // instead of re-reading the Authorization header.
        Auth::forgetGuards();
        $this->withToken($phone)->getJson(self::ME)->assertUnauthorized();

        Auth::forgetGuards();
        $this->withToken($tablet)->getJson(self::ME)->assertOk();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson(self::LOGOUT)->assertUnauthorized();
    }

    // --- Password reset --------------------------------------------------

    public function test_forgot_password_answers_identically_for_known_and_unknown_addresses(): void
    {
        $this->customer();

        // Notifications are NOT faked here on purpose: the stock reset mail is
        // rendered inline, which is what proves the reset URL resolves without a
        // web route named password.reset.
        $known = $this->postJson(self::FORGOT, ['email' => 'dana@example.com'])->assertOk();
        $unknown = $this->postJson(self::FORGOT, ['email' => 'ghost@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'dana@example.com']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ghost@example.com']);
    }

    public function test_forgot_password_still_validates_the_address(): void
    {
        $this->postJson(self::FORGOT, ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // --- Profile ---------------------------------------------------------

    public function test_profile_can_be_read_and_patched(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $this->getJson(self::PROFILE)->assertOk()->assertJsonPath('user.ulid', $user->ulid);

        $this->patchJson(self::PROFILE, [
            'name' => 'Dana R. Reyes',
            'phone' => '555.123.4567',
            // Ignored: swapping the address is an identity change, not a profile edit.
            'email' => 'someone.else@example.com',
        ])->assertOk()->assertJsonPath('user.name', 'Dana R. Reyes');

        $user->refresh();
        $this->assertSame('Dana R. Reyes', $user->name);
        $this->assertSame('5551234567', $user->phone_normalized);
        $this->assertSame('dana@example.com', $user->email);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson(self::PROFILE)->assertUnauthorized();
        $this->patchJson(self::PROFILE, ['name' => 'Nobody'])->assertUnauthorized();
    }

    // --- Address book ----------------------------------------------------

    public function test_first_saved_address_becomes_the_default_and_only_one_stays_default(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $first = $this->postJson(self::ADDRESSES, [
            'label' => 'Home',
            'line1' => '18 Fell Street',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94102',
            'country_code' => 'us',
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.country_code', 'US')
            ->assertJsonPath('data.location_type', 'residential')
            ->json('data.id');

        $second = $this->postJson(self::ADDRESSES, [
            'label' => 'Dealership',
            'line1' => '900 Auction Row',
            'city' => 'Reno',
            'state' => 'NV',
            'location_type' => 'dealer',
            'is_default' => true,
        ])->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');

        $this->assertFalse(Address::query()->findOrFail($first)->is_default);

        $this->getJson(self::ADDRESSES)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second);   // default first

        // Deleting the default promotes a survivor rather than leaving none.
        $this->deleteJson(self::ADDRESSES.'/'.$second)->assertNoContent();
        $this->assertSoftDeleted('addresses', ['id' => $second]);
        $this->assertTrue(Address::query()->findOrFail($first)->is_default);
    }

    public function test_address_writes_are_validated(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson(self::ADDRESSES, ['city' => 'Reno', 'location_type' => 'spaceport'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['line1', 'location_type']);
    }

    public function test_addresses_belonging_to_another_user_are_invisible(): void
    {
        $owner = $this->customer();
        $address = $owner->addresses()->create([
            'line1' => '18 Fell Street',
            'city' => 'San Francisco',
            'country_code' => 'US',
            'is_default' => true,
        ]);

        $intruder = $this->customer(['email' => 'mallory@example.com']);
        Sanctum::actingAs($intruder);

        $this->getJson(self::ADDRESSES)->assertOk()->assertJsonCount(0, 'data');

        // 404, not 403: the id is not confirmed to exist.
        $this->patchJson(self::ADDRESSES.'/'.$address->id, ['city' => 'Oakland'])->assertNotFound();
        $this->deleteJson(self::ADDRESSES.'/'.$address->id)->assertNotFound();

        $this->assertSame('San Francisco', $address->refresh()->city);
        $this->assertNotSoftDeleted($address);
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'email' => 'dana@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Dana Reyes',
            'email' => 'dana@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }
}
