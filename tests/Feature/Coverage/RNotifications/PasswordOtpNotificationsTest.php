<?php

namespace Tests\Feature\Coverage\RNotifications;

use App\Constants\GeneralConstants;
use App\Events\OtpWasSent;
use App\Events\PasswordResetExpirationWasRefreshed;
use App\Events\PasswordResetWasCreated;
use App\Events\ResetPasswordSetup;
use App\Listeners\PasswordResetOtp;
use App\Listeners\PasswordSetupOtp;
use App\Listeners\SendOtp;
use App\Models\AdminUser;
use App\Models\Otp;
use App\Models\PasswordReset;
use App\Models\PasswordSetup;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SendPasswordResetEmailNotification;
use App\Notifications\SendPasswordSetupEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Password reset / setup OTP pipeline: the listeners create an OTP, OtpWasSent routes it to the matching
 * notification, and the notifications build the reset / setup links for the right audience.
 */
class PasswordOtpNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_URL = 'https://frontend.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND_URL]);
        Notification::fake();
    }

    #[Test]
    public function theResetListenerCreatesAnOtpAndEmailsTheResetLinkToIt(): void
    {
        $reset = $this->createPasswordReset($this->createCustomer('reset.me@example.test'));
        $this->assertNull($reset->otp);

        (new PasswordResetOtp())->handle(new PasswordResetWasCreated($reset));

        $otp = $reset->fresh()->otp;
        $this->assertNotNull($otp);
        $this->assertSame('reset.me@example.test', $otp->receiver);
        $this->assertSame('password_reset', $otp->otpable_type);
        Notification::assertSentTo($otp, SendPasswordResetEmailNotification::class);
        Notification::assertSentTimes(SendPasswordResetEmailNotification::class, 1);
        Notification::assertNotSentTo($otp, SendPasswordSetupEmailNotification::class);
    }

    #[Test]
    public function refreshingAResetExpirationReplacesTheOtpAndSendsAnotherEmail(): void
    {
        $reset = $this->createPasswordReset($this->createCustomer('again@example.test'));
        $listener = new PasswordResetOtp();
        $listener->handle(new PasswordResetWasCreated($reset));
        $firstOtp = $reset->fresh()->otp;

        $listener->handle(new PasswordResetExpirationWasRefreshed($reset));

        $this->assertDatabaseMissing('otps', ['uuid' => $firstOtp->uuid]);
        $this->assertSame(1, Otp::query()->where('otpable_id', $reset->uuid)->count());
        Notification::assertSentTimes(SendPasswordResetEmailNotification::class, 2);
    }

    #[Test]
    public function theSetupListenerCreatesAnOtpAndEmailsTheSetupLinkToIt(): void
    {
        $setup = PasswordSetup::create(['email' => 'new.staff@example.test', 'type' => PasswordSetup::TYPE_SETUP]);

        (new PasswordSetupOtp())->handle(new ResetPasswordSetup($setup));

        $otp = $setup->fresh()->otp;
        $this->assertNotNull($otp);
        $this->assertSame('password_setup', $otp->otpable_type);
        Notification::assertSentTo($otp, SendPasswordSetupEmailNotification::class);
        Notification::assertNotSentTo($otp, SendPasswordResetEmailNotification::class);
    }

    #[Test]
    public function theSendOtpListenerIgnoresOtpsForOtherOtpables(): void
    {
        $otp = new Otp(['receiver' => 'someone@example.test', 'otpable_type' => 'event']);

        (new SendOtp())->handle(new OtpWasSent($otp));

        Notification::assertNothingSent();
    }

    #[Test]
    public function theResetEmailIsOnlyDeliveredToAValidEmailAddress(): void
    {
        $notification = new SendPasswordResetEmailNotification();

        $this->assertSame(['mail'], $notification->via(new Otp(['receiver' => 'valid@example.test'])));
        $this->assertSame([], $notification->via(new Otp(['receiver' => 'not-an-email'])));
        $this->assertSame(['mail'], $notification->via((object) ['receiver' => 'plain@example.test']));
        $this->assertSame([], $notification->via(new \stdClass()));
    }

    #[Test]
    public function theSetupEmailIsOnlyDeliveredToAValidEmailAddress(): void
    {
        $notification = new SendPasswordSetupEmailNotification();

        $this->assertSame(['mail'], $notification->via(new Otp(['receiver' => 'valid@example.test'])));
        $this->assertSame([], $notification->via(new Otp(['receiver' => 'not-an-email'])));
        $this->assertSame(['mail'], $notification->via((object) ['receiver' => 'plain@example.test']));
        $this->assertSame([], $notification->via(new \stdClass()));
    }

    #[Test]
    public function aCustomerResetLinkUsesTheHomeReferrerAndGreetsTheCustomer(): void
    {
        $user = $this->createCustomer('cust@example.test', 'Carla', 'Cruz', 'Diaz');
        $otp = $this->resetOtpFor($user);

        $mail = (new SendPasswordResetEmailNotification())->toMail($otp);
        $data = $mail->viewData['data'];

        $this->assertSame('[Ticketoc] Reset your password', $mail->subject);
        $this->assertSame('emails.password_reset_email', $mail->view);
        $this->assertSame(self::FRONTEND_URL . '/set-password?uuid=' . $otp->uuid . '&referrer=home', $data['url_link']);
        $this->assertSame('Carla Diaz Cruz', $data['name']);
        $this->assertSame(self::FRONTEND_URL . '/privacy_policy', $data['privacy_policy_link']);
        $this->assertSame(self::FRONTEND_URL . '/images/logo/ticketoc_dark_nobg.png', $data['logo_url']);
        $this->assertSame(date('Y'), $data['current_year']);

        $html = (string) $mail->render();
        $this->assertStringContainsString('Hi Carla Diaz Cruz!', $html);
        $this->assertStringContainsString(e($data['url_link']), $html);
    }

    #[Test]
    public function anAdminResetLinkUsesTheAdminReferrer(): void
    {
        $admin = $this->createAdminUser('root@example.test', isAdminRole: true);
        $otp = $this->resetOtpFor($admin);

        $data = (new SendPasswordResetEmailNotification())->toMail($otp)->viewData['data'];

        $this->assertStringEndsWith('&referrer=admin', $data['url_link']);
        $this->assertSame('Ada Admin', $data['name']);
    }

    #[Test]
    public function aNonAdminStaffResetLinkUsesTheOrganizerReferrer(): void
    {
        $merchant = $this->createAdminUser('merchant@example.test', isAdminRole: false);
        $otp = $this->resetOtpFor($merchant);

        $data = (new SendPasswordResetEmailNotification())->toMail($otp)->viewData['data'];

        $this->assertStringEndsWith('&referrer=organizer', $data['url_link']);
        $this->assertSame('Ada Admin', $data['name']);
    }

    #[Test]
    public function aResetForADeletedAccountStillBuildsALinkWithAnEmptyName(): void
    {
        $otp = $this->resetOtpFor($this->createCustomer('gone@example.test'));
        $otp->otpable->forceFill(['resettable_id' => (string) Str::uuid()])->save();

        $data = (new SendPasswordResetEmailNotification())->toMail($otp->fresh())->viewData['data'];

        $this->assertSame('', $data['name']);
        $this->assertStringEndsWith('&referrer=home', $data['url_link']);
    }

    #[Test]
    public function theSetupEmailGreetsAStaffMemberByName(): void
    {
        $this->createAdminUser('staff@example.test', isAdminRole: false);
        $otp = $this->setupOtpFor('staff@example.test');

        $mail = (new SendPasswordSetupEmailNotification())->toMail($otp);
        $data = $mail->viewData['data'];

        $this->assertSame('[Ticketoc] Set Your Password', $mail->subject);
        $this->assertSame('emails.password_set_email', $mail->view);
        $this->assertSame('Ada Admin', $data['name']);
        $this->assertSame(self::FRONTEND_URL . '/update_password?uuid=' . $otp->uuid, $data['url_link']);
        $this->assertSame(self::FRONTEND_URL . '/privacy-policy', $data['privacy_policy_link']);
        $this->assertSame(self::FRONTEND_URL . '/terms-and-conditions', $data['tc_link']);

        $html = (string) $mail->render();
        $this->assertStringContainsString(e($data['url_link']), $html);
        $this->assertStringContainsString($data['message'], $html);
    }

    #[Test]
    public function theSetupEmailGreetsACustomerByName(): void
    {
        $this->createCustomer('buyer@example.test', 'Ben', 'Bautista', 'Bo');
        $otp = $this->setupOtpFor('buyer@example.test');

        $data = (new SendPasswordSetupEmailNotification())->toMail($otp)->viewData['data'];

        $this->assertSame('Ben Bo Bautista', $data['name']);
    }

    #[Test]
    public function theSetupEmailLeavesTheNameBlankForAnUnknownAddress(): void
    {
        $otp = $this->setupOtpFor('stranger@example.test');

        $data = (new SendPasswordSetupEmailNotification())->toMail($otp)->viewData['data'];

        $this->assertSame('', $data['name']);
    }

    private function createCustomer(string $email, string $first = 'Cora', string $last = 'Customer', string $middle = 'M'): User
    {
        $role = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );

        return User::factory()->create([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
        ]);
    }

    private function createAdminUser(string $email, bool $isAdminRole): AdminUser
    {
        $role = Role::create([
            'name' => $isAdminRole ? 'Admin' : 'Merchant',
            'code' => 'role-' . Str::uuid()->toString(),
            'is_admin' => $isAdminRole,
        ]);

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function createPasswordReset(User|AdminUser $account): PasswordReset
    {
        return PasswordReset::create([
            'email' => $account->email,
            'resettable_id' => $account->uuid,
            'resettable_type' => $account::class,
        ]);
    }

    private function resetOtpFor(User|AdminUser $account): Otp
    {
        $reset = $this->createPasswordReset($account);
        $reset->sendOtp();

        return $reset->fresh()->otp;
    }

    private function setupOtpFor(string $email): Otp
    {
        $setup = PasswordSetup::create(['email' => $email, 'type' => PasswordSetup::TYPE_SETUP]);
        $setup->sendOtp();

        return $setup->fresh()->otp;
    }
}
