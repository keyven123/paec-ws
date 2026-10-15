<?php

namespace App\Notifications;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EventTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CartReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $cartUuid)
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
        $cart = Cart::query()
            ->where('uuid', $this->cartUuid)
            ->with(['user', 'items.event', 'items.eventLocation'])
            ->firstOrFail();
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $count = $cart->items->count();

        $ticketNames = EventTicket::query()
            ->whereIn('uuid', $cart->items->flatMap(fn (CartItem $item) => array_column($item->tickets, 'event_ticket_uuid')))
            ->pluck('name', 'uuid');

        $data = [
            'email_page_title' => 'Your cart is waiting',
            'customer_name' => trim((string) $cart->user->first_name) ?: 'there',
            'email' => $cart->user->email,
            'activity_count_label' => $count === 1 ? '1 activity' : "{$count} activities",
            'items' => $cart->items->map(fn (CartItem $item) => [
                'name' => $item->event?->event_name ?? ($item->details['attractionName'] ?? 'Activity'),
                'location' => $item->details['location'] ?? $item->eventLocation?->name,
                'visit_date' => $item->visit_date->format('D, F j, Y'),
                'tickets' => collect($item->tickets)
                    ->map(fn (array $ticket) => ((int) $ticket['quantity']) . ' × ' . ($ticketNames[$ticket['event_ticket_uuid']] ?? 'Ticket'))
                    ->implode(', '),
            ])->all(),
            'checkout_url' => $frontend . '/checkout',
            'privacy_url' => $frontend . '/privacy-policy',
            'terms_url' => $frontend . '/terms-and-conditions',
            'support_email' => config('mail.from.address'),
            'footer_reason' => 'You received this email because you added tickets to your PAEC cart.',
            'year' => now()->format('Y'),
        ];

        return (new MailMessage())
            ->subject('Your PAEC cart is waiting for payment')
            ->view('emails.paec.cart-reminder', ['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['cart_uuid' => $this->cartUuid];
    }
}
