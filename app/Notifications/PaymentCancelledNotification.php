<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Concerns\BuildsOrderEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentCancelledNotification extends Notification implements ShouldQueue
{
    use BuildsOrderEmail;
    use Queueable;

    public function __construct(public string $transactionUuid)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transaction = Transaction::query()
            ->where('uuid', $this->transactionUuid)
            ->with(['user', 'event', 'transactionOrders.eventTicket'])
            ->firstOrFail();

        $failed = $transaction->payment_status === Transaction::PAYMENT_STATUS['FAILED'];

        $payload = array_merge($this->orderSummary($transaction), [
            'email_page_title' => 'Order cancelled',
            'reason' => $failed
                ? 'The payment provider could not complete your payment, so the order was cancelled.'
                : 'The payment was cancelled before it was completed, so the order was cancelled.',
            'cancelled_at' => $transaction->updated_at?->timezone('Asia/Manila')->format('F j, Y g:i A'),
        ]);

        return $this->withPaecCopy(
            (new MailMessage())
                ->subject("Your PAEC order {$transaction->order_number} was cancelled")
                ->view('emails.paec.booking-cancelled', ['data' => $payload])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['transaction_uuid' => $this->transactionUuid];
    }
}
