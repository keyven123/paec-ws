<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Events\OtpWasSent;
use App\Events\PasswordResetWasCreated;
use App\Events\ResetPasswordSetup;
use App\Models\AdminUser;
use App\Models\Otp;
use App\Models\PasswordReset;
use App\Models\PasswordSetup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'N3wPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Event::fake([OtpWasSent::class, PasswordResetWasCreated::class, ResetPasswordSetup::class]);
    }

    private function resetFor(Model $resettable): PasswordReset
    {
        return PasswordReset::create([
            'email' => $resettable->email,
            'resettable_id' => $resettable->uuid,
            'resettable_type' => get_class($resettable),
        ]);
    }

    private function otpFor(Model $otpable, string $morphType, string $receiver, array $attributes = []): Otp
    {
        $otp = Otp::create([
            'secret' => '123456',
            'receiver' => $receiver,
            'otpable_type' => $morphType,
            'otpable_id' => $otpable->uuid,
        ]);

        if ($attributes !== []) {
            $otp->forceFill($attributes)->save();
        }

        return $otp;
    }

    #[Test]
    public function confirmPasswordResetRejectsExpiredOtp(): void
    {
        $user = $this->createCustomer();
        $otp = $this->otpFor($this->resetFor($user), 'password_reset', $user->email, [
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/password_reset/confirm', ['uuid' => $otp->uuid])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Your password reset request has timed out.');

        $this->assertDatabaseHas('otps', ['uuid' => $otp->uuid]);
    }

    #[Test]
    public function confirmPasswordResetForAdminConfirmsResetAndConsumesOtp(): void
    {
        $admin = $this->createSuperAdmin();
        $reset = $this->resetFor($admin);
        $otp = $this->otpFor($reset, 'password_reset', $admin->email);

        $this->postJson('/api/v1/password_reset/confirm', ['uuid' => $otp->uuid])
            ->assertOk()
            ->assertExactJson(['data' => ['user_type' => 'admin', 'password_reset_uuid' => $reset->uuid]]);

        $this->assertNotNull($reset->fresh()->confirmed_at);
        $this->assertDatabaseMissing('otps', ['uuid' => $otp->uuid]);
    }

    #[Test]
    public function confirmPasswordResetWithEmailVerificationMarksCustomerVerified(): void
    {
        $user = $this->createCustomer(['email_verified_at' => null]);
        $reset = $this->resetFor($user);
        $otp = $this->otpFor($reset, 'password_reset', $user->email);

        $this->postJson('/api/v1/password_reset/confirm', ['uuid' => $otp->uuid, 'type' => 'email_verification'])
            ->assertOk()
            ->assertJsonPath('data.user_type', 'user')
            ->assertJsonPath('data.password_reset_uuid', $reset->uuid);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Test]
    public function updatePasswordChangesCustomerPassword(): void
    {
        $user = $this->createCustomer(['email_verified_at' => null]);
        $reset = $this->resetFor($user);

        $this->postJson('/api/v1/password_reset/update_password', [
            'user_type' => 'user',
            'password_reset_uuid' => $reset->uuid,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.user_type', 'user')
            ->assertJsonPath('data.message', 'You have successfully changed your password.');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('password_resets', ['uuid' => $reset->uuid]);
    }

    #[Test]
    public function updatePasswordChangesAdminPassword(): void
    {
        $admin = $this->createSuperAdmin();
        $reset = $this->resetFor($admin);

        $this->postJson('/api/v1/password_reset/update_password', [
            'user_type' => 'admin',
            'password_reset_uuid' => $reset->uuid,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.user_type', 'admin')
            ->assertJsonPath('data.is_admin', true);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password));
    }

    #[Test]
    public function confirmExpiredPasswordResetRejectsUnknownOtp(): void
    {
        $this->postJson('/api/v1/password_reset/confirm_expired', ['uuid' => '00000000-0000-0000-0000-000000000000'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Password request is invalid.');
    }

    #[Test]
    public function confirmExpiredPasswordResetRejectsExpiredOtp(): void
    {
        $setup = PasswordSetup::create(['email' => 'someone@test.com', 'type' => PasswordSetup::TYPE_SETUP]);
        $otp = $this->otpFor($setup, 'password_setup', $setup->email, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/password_reset/confirm_expired', ['uuid' => $otp->uuid])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Your password reset request has timed out.');
    }

    #[Test]
    public function confirmExpiredPasswordResetIdentifiesAdminAccount(): void
    {
        $admin = $this->createSuperAdmin();
        $setup = PasswordSetup::create(['email' => $admin->email, 'type' => PasswordSetup::TYPE_SETUP]);
        $otp = $this->otpFor($setup, 'password_setup', $admin->email);

        $this->postJson('/api/v1/password_reset/confirm_expired', ['uuid' => $otp->uuid])
            ->assertOk()
            ->assertExactJson(['data' => [
                'password_set_uuid' => $setup->uuid,
                'user_type' => 'admin',
                'is_admin' => true,
            ]]);

        $this->assertNotNull($setup->fresh()->confirmed_at);
        $this->assertDatabaseMissing('otps', ['uuid' => $otp->uuid]);
    }

    #[Test]
    public function confirmExpiredPasswordResetIdentifiesCustomerAccount(): void
    {
        $user = $this->createCustomer();
        $setup = PasswordSetup::create(['email' => $user->email, 'type' => PasswordSetup::TYPE_SETUP]);
        $otp = $this->otpFor($setup, 'password_setup', $user->email);

        $this->postJson('/api/v1/password_reset/confirm_expired', ['uuid' => $otp->uuid])
            ->assertOk()
            ->assertJsonPath('data.user_type', 'user')
            ->assertJsonPath('data.is_admin', false);
    }

    #[Test]
    public function confirmExpiredPasswordResetDefaultsToAdminWhenNoAccountMatches(): void
    {
        $setup = PasswordSetup::create(['email' => 'nobody@test.com', 'type' => PasswordSetup::TYPE_SETUP]);
        $otp = $this->otpFor($setup, 'password_setup', $setup->email);

        $this->postJson('/api/v1/password_reset/confirm_expired', ['uuid' => $otp->uuid])
            ->assertOk()
            ->assertJsonPath('data.user_type', 'admin')
            ->assertJsonPath('data.is_admin', false);
    }

    #[Test]
    public function updateExpiredPasswordSetsAdminPassword(): void
    {
        $admin = $this->createSuperAdmin(['is_first_time_login' => true]);
        $setup = PasswordSetup::create(['email' => $admin->email, 'type' => PasswordSetup::TYPE_SETUP]);

        $this->postJson('/api/v1/password_reset/update_expired_password', [
            'password_set_uuid' => $setup->uuid,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertExactJson(['data' => [
                'message' => 'You have successfully set your password.',
                'user_type' => 'admin',
                'is_admin' => true,
            ]]);

        $admin->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->password));
        $this->assertFalse($admin->is_first_time_login);
        $this->assertDatabaseMissing('password_setups', ['uuid' => $setup->uuid]);
    }

    #[Test]
    public function updateExpiredPasswordReturns400WhenNoAccountMatchesSetupEmail(): void
    {
        $setup = PasswordSetup::create(['email' => 'ghost@test.com', 'type' => PasswordSetup::TYPE_SETUP]);

        $this->postJson('/api/v1/password_reset/update_expired_password', [
            'password_set_uuid' => $setup->uuid,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertStatus(400)
            ->assertExactJson(['data' => [
                'message' => 'Failed to set password. Please contact support.',
                'user_type' => 'user',
                'is_admin' => false,
            ]]);

        $this->assertDatabaseHas('password_setups', ['uuid' => $setup->uuid]);
    }

    #[Test]
    public function updateExpiredPasswordFailsWhenSetupVanishesAfterValidation(): void
    {
        $setup = PasswordSetup::create(['email' => 'race@test.com', 'type' => PasswordSetup::TYPE_SETUP]);

        // The `exists` rule queries the table directly; hide the row from Eloquent only.
        PasswordSetup::addGlobalScope('vanished', fn (Builder $query) => $query->whereRaw('1 = 0'));

        try {
            $this->withoutExceptionHandling();
            $this->expectException(\App\Exceptions\NoPasswordSetupFoundException::class);
            $this->expectExceptionMessage('No password setup found.');

            $this->postJson('/api/v1/password_reset/update_expired_password', [
                'password_set_uuid' => $setup->uuid,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);
        } finally {
            Model::clearBootedModels();
        }
    }
}
