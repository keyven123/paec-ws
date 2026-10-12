<?php

namespace Tests\Feature\Coverage\RNotifications;

use App\Models\EventReminderLog;
use App\Notifications\EventReminderNotification;
use App\Notifications\PaymentSuccessfulNotification;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\Notifications\Concerns\BuildsNotificationFixtures;
use Tests\Feature\Coverage\RNotifications\Concerns\BuildsTicketTransactions;
use Tests\TestCase;

/**
 * Event portrait embedding, inline QR codes, attachments and array payloads of the
 * ticket purchase / reminder emails.
 */
class TicketPurchaseNotificationsTest extends TestCase
{
    use BuildsNotificationFixtures;
    use BuildsTicketTransactions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND_URL]);
    }

    /**
     * @return array<string, array{0: Closure(string): MailMessage}>
     */
    public static function ticketMails(): array
    {
        return [
            'event reminder (7 days)' => [
                fn (string $uuid, object $notifiable) => (new EventReminderNotification($uuid, EventReminderLog::TYPE_7_DAYS))
                    ->toMail($notifiable),
            ],
            'payment successful' => [
                fn (string $uuid, object $notifiable) => (new PaymentSuccessfulNotification($uuid))->toMail($notifiable),
            ],
        ];
    }

    #[Test]
    #[DataProvider('ticketMails')]
    public function itEmbedsTheEventPortraitOnlyWhenTheRemoteFetchReturnsUsableImageBytes(Closure $toMail): void
    {
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction();
        $scenarios = $this->imageEmbedScenarios('event-portrait', svgHasOwnExtension: true);
        $this->fakeImageResponses(array_column($scenarios, 0));

        foreach ($scenarios as $label => [, $expectedEmbed]) {
            $mail = $toMail($transaction->uuid, $transaction->user);

            $this->assertSame(self::PORTRAIT_URL, $mail->viewData['data']['event_image'], $label);
            $this->assertSame($expectedEmbed, $mail->viewData['data']['event_portrait_embed'], $label);
        }

        $answered = array_filter(array_column($scenarios, 0), fn ($response) => ! $response instanceof \Throwable);
        Http::assertSentCount(count($answered));
        Http::assertSent(fn ($request) => $request->url() === self::PORTRAIT_URL
            && $request->hasHeader('Accept', 'image/*'));
    }

    #[Test]
    #[DataProvider('ticketMails')]
    public function itSkipsThePortraitFetchWhenTheEventHasNoPortrait(Closure $toMail): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction(withPortrait: false);

        $mail = $toMail($transaction->uuid, $transaction->user);

        $this->assertNull($mail->viewData['data']['event_image']);
        $this->assertNull($mail->viewData['data']['event_portrait_embed']);
        Http::assertNothingSent();
    }

    #[Test]
    public function theFortyEightHourReminderRendersAnInlineQrPngForTicketsWithAQrCode(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction(withPortrait: false, qrCode: 'TICKET-QR-PAYLOAD-123');

        $mail = (new EventReminderNotification($transaction->uuid, EventReminderLog::TYPE_48_HOURS))
            ->toMail($transaction->user);
        $ticket = $mail->viewData['data']['tickets'][0];

        $this->assertTrue($mail->viewData['data']['show_qr_codes']);
        $this->assertSame('TICKET-QR-PAYLOAD-123', $ticket['raw_qr_code']);
        $this->assertIsString($ticket['qr_png']);
        $this->assertStringStartsWith("\x89PNG", $ticket['qr_png']);
    }

    #[Test]
    public function theReminderArrayRepresentationIdentifiesTheTransactionsAndReminderType(): void
    {
        $notification = new EventReminderNotification(['tx-1', 'tx-2'], EventReminderLog::TYPE_12_HOURS);

        $this->assertSame([
            'transaction_uuid' => 'tx-1',
            'transaction_uuids' => ['tx-1', 'tx-2'],
            'reminder_type' => EventReminderLog::TYPE_12_HOURS,
        ], $notification->toArray(new \stdClass()));
    }

    #[Test]
    public function thePaymentEmailAttachesTicketAndCouponFilesFromTheExportService(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $stub = $this->bindExportServiceStub(
            ticketReturn: ['data' => 'TICKET-PDF', 'filename' => 'ticket.pdf', 'mime' => 'application/pdf'],
            couponReturn: ['data' => 'COUPON-PDF', 'filename' => 'coupons.pdf', 'mime' => 'application/pdf'],
        );
        $transaction = $this->createPaidTransaction(withPortrait: false);

        $notification = new PaymentSuccessfulNotification($transaction->uuid);
        $mail = $notification->toMail($transaction->user);

        $this->assertSame(['mail'], $notification->via($transaction->user));
        $this->assertSame([], $notification->toArray($transaction->user));
        $this->assertSame('[Ticketoc] Your payment has been successful!', $mail->subject);
        $this->assertSame('emails.payment-successful-dark-v2', $mail->view);
        $this->assertSame(1, $stub->ticketCalls);
        $this->assertSame(1, $stub->couponCalls);
        $this->assertSame(
            [['ticket.pdf', 'TICKET-PDF', 'application/pdf'], ['coupons.pdf', 'COUPON-PDF', 'application/pdf']],
            array_map(fn ($a) => [$a['name'], $a['data'], $a['options']['mime']], $mail->rawAttachments),
        );
    }

    #[Test]
    public function theUpgradeContextUsesUpgradeWordingAndRendersTheTemplate(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction(withPortrait: false);

        $mail = (new PaymentSuccessfulNotification($transaction->uuid, PaymentSuccessfulNotification::CONTEXT_UPGRADE))
            ->toMail($transaction->user);
        $html = (string) $mail->render();

        $this->assertSame('[Ticketoc] Your ticket has been upgraded!', $mail->subject);
        $this->assertSame('Ticket upgraded', $mail->viewData['data']['email_page_title']);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertStringContainsString('Coverage Concert', $html);
        $this->assertStringContainsString('ORD-RNOTIF-0001', $html);
    }
}
