<?php

namespace Tests\Feature\Coverage\RNotifications;

use App\Models\ConfirmationToken;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\SendInvitesToOrganizer;
use App\Notifications\SendOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Account-level emails: verification code, organizer invitation and login OTP.
 */
class AccountEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_URL = 'https://frontend.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Ticketoc',
            'app.frontend_url' => self::FRONTEND_URL . '/',
            'app.support_email' => 'support@example.test',
            'auth.confirmation_token_ttl' => 15,
        ]);
    }

    #[Test]
    public function theVerificationEmailShowsTheCodeAndLinksToTheFrontendPolicies(): void
    {
        $role = Role::create(['name' => 'Customer', 'code' => 'customer-' . Str::uuid()->toString()]);
        $user = User::factory()->create(['role_uuid' => $role->uuid, 'first_name' => 'Liza']);
        $token = ConfirmationToken::createForUser($user->uuid, 15);

        $notification = new EmailVerificationNotification($token);
        $mail = $notification->toMail($user);

        $this->assertSame(['mail'], $notification->via($user));
        $this->assertSame([], $notification->toArray($user));
        $this->assertSame('emails.email-verification', $mail->view);
        $this->assertSame(__('emails.verification.subject', ['app_name' => 'Ticketoc']), $mail->subject);
        $this->assertSame($user, $mail->viewData['notifiable']);
        $this->assertSame($token, $mail->viewData['confirmationToken']);
        $this->assertSame(self::FRONTEND_URL . '/privacy-policy', $mail->viewData['privacy_policy_link']);
        $this->assertSame(self::FRONTEND_URL . '/terms-and-conditions', $mail->viewData['tc_link']);
        $this->assertSame(
            __('messages.verification.greeting', ['first_name' => 'Liza']),
            $mail->viewData['copy']['greeting'],
        );
        $this->assertSame(
            __('messages.verification.important', ['ttl' => 15, 'app_name' => 'Ticketoc']),
            $mail->viewData['copy']['important'],
        );
        $this->assertStringContainsString($token->getPlainToken(), (string) $mail->render());
    }

    #[Test]
    public function theVerificationEmailFallsBackToPlaceholderLinksWithoutAFrontendUrl(): void
    {
        config(['app.frontend_url' => '', 'app.url' => '']);
        $user = new User(['first_name' => 'Liza']);

        $mail = (new EmailVerificationNotification(new ConfirmationToken(['token' => '123456'])))->toMail($user);

        $this->assertSame('#', $mail->viewData['privacy_policy_link']);
        $this->assertSame('#', $mail->viewData['tc_link']);
    }

    #[Test]
    public function theOrganizerInvitationLinksToOnboardingWithTheSecretAndEmail(): void
    {
        config(['app.frontend_url' => self::FRONTEND_URL]);
        $organization = Organization::factory()->create([
            'name' => 'Acme Events',
            'email' => 'owner@acme.test',
            'representative_first_name' => 'Ana',
            'representative_last_name' => 'Reyes',
        ]);

        $notification = new SendInvitesToOrganizer('s3cr3t-token');
        $mail = $notification->toMail($organization);
        $data = $mail->viewData['data'];

        $this->assertSame(['mail'], $notification->via($organization));
        $this->assertSame('s3cr3t-token', $notification->secret);
        $this->assertSame('emails.sent-invites', $mail->view);
        $this->assertSame(__('emails.sent_invites.subject', ['app_name' => 'Ticketoc']), $mail->subject);
        $this->assertSame(
            self::FRONTEND_URL . '/organizer/onboarding?secret=s3cr3t-token&email=owner@acme.test',
            $data['url'],
        );
        $this->assertSame(__('emails.sent_invites.greeting', ['name' => 'Acme Events']), $data['greeting']);
        $this->assertSame(
            __('emails.sent_invites.intro_line_1', ['representative_name' => 'Ana Reyes']),
            $data['intro_line_1'],
        );
        $this->assertSame(
            __('emails.sent_invites.outro_line_2', ['support_email' => 'support@example.test']),
            $data['outro_line_2'],
        );
        $this->assertSame(__('emails.sent_invites.important', ['ttl' => 24]), $data['important']);
        $this->assertCount(4, $data['instructions']);
        $this->assertStringContainsString(
            e($data['url']),
            (string) $mail->render(),
        );
    }

    #[Test]
    public function theLoginOtpEmailCarriesTheCodeForTheRecipient(): void
    {
        $notification = new SendOtp([
            'email' => 'officer@example.test',
            'code' => '482913',
            'company_name' => 'GSSO',
        ]);
        $mail = $notification->toMail(new \stdClass());

        $this->assertSame(['mail'], $notification->via(new \stdClass()));
        $this->assertSame('emails.otp', $mail->view);
        $this->assertSame('[GSSO-NDC] Login One-Time Password(OTP)', $mail->subject);
        $this->assertSame(
            ['email' => 'officer@example.test', 'code' => '482913', 'company_name' => 'GSSO'],
            $mail->viewData['data'],
        );
    }
}
