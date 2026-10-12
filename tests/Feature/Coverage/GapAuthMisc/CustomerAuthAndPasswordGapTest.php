<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Events\OtpWasSent;
use App\Events\PasswordResetExpirationWasRefreshed;
use App\Events\PasswordResetWasCreated;
use App\Http\Repositories\PasswordResetRepository;
use App\Http\Repositories\PasswordSetupRepository;
use App\Models\PasswordReset;
use App\Models\PasswordSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerAuthAndPasswordGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OtpWasSent::class,
            PasswordResetWasCreated::class,
            PasswordResetExpirationWasRefreshed::class,
        ]);
    }

    #[Test]
    public function customerLoginWithUnknownEmailReturnsUnauthorized(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'ghost@test.com',
            'password' => 'password123',
        ])->assertStatus(401)
            ->assertExactJson(['message' => 'invalid credentials']);
    }

    #[Test]
    public function customerLoginForMigratedUserInitiatesPasswordReset(): void
    {
        $user = $this->makeCustomer(['is_migrated' => true]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(201)
            ->assertJsonPath('data.message', fn (string $m) => str_contains($m, 'reset your password'));

        $this->assertDatabaseHas('password_resets', [
            'email' => $user->email,
            'resettable_id' => $user->uuid,
            'resettable_type' => User::class,
        ]);
        Event::assertDispatched(OtpWasSent::class);
    }

    #[Test]
    public function initiateRefreshesExpirationForExistingCustomerReset(): void
    {
        $user = $this->makeCustomer();
        $repository = app(PasswordResetRepository::class);

        $first = $repository->initiate(['email' => $user->email]);
        $second = $repository->initiate(['email' => $user->email]);

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertNotNull($second->expires_at);
        $this->assertDatabaseCount('password_resets', 1);
        Event::assertDispatched(
            PasswordResetExpirationWasRefreshed::class,
            fn ($event) => $event->passwordReset->uuid === $first->uuid
        );
    }

    #[Test]
    public function initiateAdminReturnsNullForUnknownOrInactiveAdmin(): void
    {
        $inactive = $this->makeAdmin($this->makeRole(), ['status' => 'inactive']);
        $repository = app(PasswordResetRepository::class);

        $this->assertNull($repository->initiateAdmin(['email' => 'missing@test.com']));
        $this->assertNull($repository->initiateAdmin(['email' => $inactive->email]));
        $this->assertDatabaseCount('password_resets', 0);
    }

    #[Test]
    public function initiateAdminRefreshesExpirationForExistingReset(): void
    {
        $admin = $this->makeAdmin($this->makeRole());
        $repository = app(PasswordResetRepository::class);

        $first = $repository->initiateAdmin(['email' => $admin->email]);
        $second = $repository->initiateAdmin(['email' => $admin->email]);

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertNotNull($second->expires_at);
        Event::assertDispatched(PasswordResetExpirationWasRefreshed::class);
    }

    #[Test]
    public function changePasswordClaimsGuestCustomer(): void
    {
        $guest = $this->makeCustomer([
            'provider' => User::GUEST_PROVIDER,
            'email_verified_at' => null,
            'is_migrated' => true,
        ]);
        $reset = PasswordReset::create([
            'email' => $guest->email,
            'resettable_id' => $guest->uuid,
            'resettable_type' => User::class,
        ]);

        $result = app(PasswordResetRepository::class)->changePassword($reset, [
            'user_type' => 'user',
            'password' => 'brand-new-pass',
        ]);

        $this->assertTrue($result);
        $guest->refresh();
        $this->assertNull($guest->provider);
        $this->assertNotNull($guest->email_verified_at);
        $this->assertFalse((bool) $guest->is_migrated);
        $this->assertTrue(Hash::check('brand-new-pass', $guest->password));
        $this->assertDatabaseMissing('password_resets', ['uuid' => $reset->uuid]);
    }

    #[Test]
    public function processPasswordSetupUpdatesCustomerWhenNoAdminMatches(): void
    {
        $user = $this->makeCustomer();
        $setup = PasswordSetup::create([
            'email' => $user->email,
            'type' => PasswordSetup::TYPE_SETUP,
        ]);

        $result = app(PasswordSetupRepository::class)->processPasswordSetup($setup, 'customer-new-pass');

        $this->assertSame(['success' => true, 'user_type' => 'user', 'is_admin' => null], $result);
        $this->assertTrue(Hash::check('customer-new-pass', $user->fresh()->password));
        $this->assertDatabaseMissing('password_setups', ['uuid' => $setup->uuid]);
    }

    #[Test]
    public function processPasswordSetupVerifiesUnverifiedAdmin(): void
    {
        $admin = $this->makeAdmin($this->makeRole(), [
            'email_verified_at' => null,
            'is_first_time_login' => true,
        ]);
        $setup = PasswordSetup::create([
            'email' => $admin->email,
            'type' => PasswordSetup::TYPE_SETUP,
        ]);

        $result = app(PasswordSetupRepository::class)->processPasswordSetup($setup, 'admin-new-pass');

        $this->assertSame('admin', $result['user_type']);
        $this->assertTrue((bool) $result['is_admin']);
        $admin->refresh();
        $this->assertNotNull($admin->email_verified_at);
        $this->assertFalse((bool) $admin->is_first_time_login);
        $this->assertTrue(Hash::check('admin-new-pass', $admin->password));
    }
}
