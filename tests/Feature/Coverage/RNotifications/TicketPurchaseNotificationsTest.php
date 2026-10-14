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
        $this->assertSame('Your PAEC booking is confirmed – Order ORD-RNOTIF-0001', $mail->subject);
        $this->assertSame('emails.paec.booking-confirmed', $mail->view);
        $this->assertTrue($mail->viewData['data']['has_ticket_file_attachments']);
        $this->assertSame(1, $stub->ticketCalls);
        $this->assertSame(1, $stub->couponCalls);
        $this->assertSame(
            [['ticket.pdf', 'TICKET-PDF', 'application/pdf'], ['coupons.pdf', 'COUPON-PDF', 'application/pdf']],
            array_map(fn ($a) => [$a['name'], $a['data'], $a['options']['mime']], $mail->rawAttachments),
        );
        $this->assertSame([], $mail->bcc);
    }

    #[Test]
    public function thePaymentEmailIsBlindCopiedToThePaecMailboxes(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->bindExportServiceStub();
        config(['mail.copy_to' => ['orders@paec.test', 'audit@paec.test']]);
        $transaction = $this->createPaidTransaction(withPortrait: false);

        $mail = (new PaymentSuccessfulNotification($transaction->uuid))->toMail($transaction->user);

        $this->assertSame([['orders@paec.test', null], ['audit@paec.test', null]], $mail->bcc);
        $this->assertFalse($mail->viewData['data']['has_ticket_file_attachments']);
    }

    #[Test]
    public function thePaymentEmailSummarisesTheOrderTicketsAndPayment(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction(withPortrait: false, qrCode: 'QR-SUMMARY-1');
        $transaction->update([
            'payment_provider' => 'paynamics',
            'payment_id' => 'PAY-20261014-SUMMARY1',
            'sub_total' => 2500,
            'discount' => 300,
            'tax_amount' => 120,
            'total_amount' => 2320,
        ]);
        $ticket = $transaction->tickets->first();
        $ticket->update(['ticket_number' => 'TKT-SUM-1', 'row' => 'A', 'col' => '4', 'valid_until' => '2026-12-24 23:59:59']);
        $transaction->transactionOrders()->create([
            'user_uuid' => $transaction->user_uuid,
            'event_ticket_uuid' => $ticket->event_ticket_uuid,
            'quantity' => 1,
            'price' => 2500,
            'total_amount' => 2500,
            'valid_until' => '2026-12-24 23:59:59',
        ]);

        $mail = (new PaymentSuccessfulNotification($transaction->uuid))->toMail($transaction->user);
        $data = $mail->viewData['data'];
        $html = (string) $mail->render();

        $this->assertSame($transaction->user->first_name, $data['customer_name']);
        $this->assertFalse($data['is_guest']);
        $this->assertSame('Paynamics', $data['payment_method']);
        $this->assertSame('PAY-20261014-SUMMARY1', $data['payment_reference']);
        $this->assertSame('October 1, 2026 10:30 AM', $data['paid_at']);
        $this->assertSame('7:00 PM – 9:00 PM', $data['event_time']);
        $this->assertSame([['name' => 'VIP', 'quantity' => 1, 'visit_date' => 'Thu, December 24, 2026', 'total' => '₱2,500.00']], $data['items']);
        $this->assertSame(['₱2,500.00', '₱300.00', '₱120.00', '₱2,320.00'], [$data['sub_total'], $data['discount'], $data['tax'], $data['total']]);
        $this->assertSame([
            'ticket_number' => 'TKT-SUM-1',
            'type' => 'VIP',
            'attendee_name' => 'Juan Dela Cruz',
            'visit_date' => 'Thu, December 24, 2026',
            'location' => null,
            'seat' => 'A-4',
            'raw_qr_code' => 'QR-SUMMARY-1',
        ], array_intersect_key($data['tickets'][0], array_flip(['ticket_number', 'type', 'attendee_name', 'visit_date', 'location', 'seat', 'raw_qr_code'])));
        $this->assertStringStartsWith("\x89PNG", $data['tickets'][0]['qr_png']);
        $this->assertStringContainsString('Your booking is confirmed', $html);
        $this->assertStringContainsString('TKT-SUM-1', $html);
        $this->assertStringContainsString('View my tickets', $html);
    }

    #[Test]
    public function aGuestBuyerIsAskedToKeepTheEmailInsteadOfOpeningTheirAccount(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction(withPortrait: false);
        $transaction->user->update(['provider' => \App\Models\User::GUEST_PROVIDER]);
        $transaction->update(['payment_provider' => 'bank-transfer']);

        $mail = (new PaymentSuccessfulNotification($transaction->uuid))->toMail($transaction->user);
        $html = (string) $mail->render();

        $this->assertTrue($mail->viewData['data']['is_guest']);
        $this->assertSame('Bank-transfer', $mail->viewData['data']['payment_method']);
        $this->assertNull($mail->viewData['data']['discount']);
        $this->assertNull($mail->viewData['data']['tax']);
        $this->assertStringContainsString('You checked out as a guest', $html);
        $this->assertStringNotContainsString('View my tickets', $html);
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

        $this->assertSame('Your PAEC ticket has been upgraded – Order ORD-RNOTIF-0001', $mail->subject);
        $this->assertSame('Ticket upgraded', $mail->viewData['data']['email_page_title']);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertStringContainsString('Coverage Concert', $html);
        $this->assertStringContainsString('ORD-RNOTIF-0001', $html);
    }
}
