<?php

namespace App\Notifications\Concerns;

use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Shared pieces of the PAEC order confirmation and cancellation emails.
 */
trait BuildsOrderEmail
{
    private const PAYMENT_METHOD_LABELS = [
        'paynamics' => 'Paynamics',
        'paymongo' => 'PayMongo',
        'paypal' => 'PayPal',
        'free' => 'Free booking',
    ];

    /**
     * Blind-copies the PAEC mailboxes so customers never see the internal address.
     */
    protected function withPaecCopy(MailMessage $mail): MailMessage
    {
        $copyTo = config('mail.copy_to', []);

        return $copyTo === [] ? $mail : $mail->bcc($copyTo);
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderSummary(Transaction $transaction): array
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $user = $transaction->user;
        $discount = (float) $transaction->discount + (float) $transaction->markup_discount;

        return [
            'customer_name' => $user ? (trim((string) $user->first_name) ?: 'there') : 'there',
            'is_guest' => $user?->provider === User::GUEST_PROVIDER,
            'event_name' => $transaction->event?->event_name ?? 'your booking',
            'order_number' => $transaction->order_number,
            'payment_method' => self::PAYMENT_METHOD_LABELS[$transaction->payment_provider] ?? ucfirst((string) $transaction->payment_provider),
            'payment_reference' => $transaction->payment_id,
            'ordered_at' => $transaction->created_at?->timezone('Asia/Manila')->format('F j, Y g:i A'),
            'items' => $transaction->transactionOrders->map(fn (TransactionOrder $order) => [
                'name' => $order->eventTicket?->name ?? 'Ticket',
                'quantity' => (int) $order->quantity,
                'visit_date' => $order->valid_until ? Carbon::parse($order->valid_until)->format('D, F j, Y') : null,
                'total' => $this->money((float) $order->price * (int) $order->quantity + (float) $order->markup + (float) $order->markup_discount),
            ])->values()->all(),
            'sub_total' => $this->money((float) $transaction->sub_total + (float) $transaction->markup_amount + (float) $transaction->markup_discount),
            'discount' => $discount > 0 ? $this->money($discount) : null,
            'tax' => (float) $transaction->tax_amount > 0 ? $this->money($transaction->tax_amount) : null,
            'total' => $this->money($transaction->total_amount),
            'my_tickets_url' => $frontend . '/account/tickets',
            'browse_url' => $frontend . '/',
            'privacy_url' => $frontend . '/privacy-policy',
            'terms_url' => $frontend . '/terms-and-conditions',
            'support_email' => config('mail.from.address'),
            'year' => now()->format('Y'),
        ];
    }

    protected function money(mixed $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }
}
