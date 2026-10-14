<?php

namespace Tests\Feature\Coverage\RNotifications;

use App\Models\Transaction;
use App\Notifications\PaymentCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\RNotifications\Concerns\BuildsTicketTransactions;
use Tests\TestCase;

class PaymentCancelledNotificationTest extends TestCase
{
    use BuildsTicketTransactions;
    use RefreshDatabase;

    private function cancelledTransaction(string $status): Transaction
    {
        $transaction = $this->createPaidTransaction(withPortrait: false);
        $transaction->tickets()->delete();
        $transaction->update([
            'payment_status' => $status,
            'payment_provider' => 'paymongo',
            'total_amount' => 1500,
        ]);
        $transaction->transactionOrders()->create([
            'user_uuid' => $transaction->user_uuid,
            'event_ticket_uuid' => $transaction->event->eventTickets()->value('uuid'),
            'quantity' => 2,
            'price' => 750,
            'total_amount' => 1500,
        ]);

        return $transaction;
    }

    #[Test]
    public function aCustomerCancellationExplainsThatNothingWasCharged(): void
    {
        $transaction = $this->cancelledTransaction(Transaction::PAYMENT_STATUS['CANCELLED']);
        $notification = new PaymentCancelledNotification($transaction->uuid);

        $mail = $notification->toMail($transaction->user);
        $html = (string) $mail->render();

        $this->assertSame(['mail'], $notification->via($transaction->user));
        $this->assertSame(['transaction_uuid' => $transaction->uuid], $notification->toArray($transaction->user));
        $this->assertSame('Your PAEC order ORD-RNOTIF-0001 was cancelled', $mail->subject);
        $this->assertSame('emails.paec.booking-cancelled', $mail->view);
        $this->assertSame('The payment was cancelled before it was completed, so the order was cancelled.', $mail->viewData['data']['reason']);
        $this->assertSame('PayMongo', $mail->viewData['data']['payment_method']);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertSame([], $mail->bcc);
        $this->assertStringContainsString('Your order was cancelled', $html);
        $this->assertStringContainsString('No payment was collected', $html);
        $this->assertStringContainsString('₱1,500.00', $html);
    }

    #[Test]
    public function aGatewayFailureSaysTheProviderCouldNotCompleteThePayment(): void
    {
        config(['mail.copy_to' => ['orders@paec.test']]);
        $transaction = $this->cancelledTransaction(Transaction::PAYMENT_STATUS['FAILED']);

        $mail = (new PaymentCancelledNotification($transaction->uuid))->toMail($transaction->user);

        $this->assertSame('The payment provider could not complete your payment, so the order was cancelled.', $mail->viewData['data']['reason']);
        $this->assertSame([['orders@paec.test', null]], $mail->bcc);
    }
}
