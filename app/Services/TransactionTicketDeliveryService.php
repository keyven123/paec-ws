<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Exceptions\UnauthorizedException;
use App\Http\Repositories\TicketRepository;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Checkout\GuestCustomerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionTicketDeliveryService
{
    public const MODE_RESENT = 'resent';

    public const MODE_TRANSFERRED = 'transferred';

    public function __construct(
        protected TicketRepository $ticketRepository,
        protected NotificationService $notificationService,
    ) {
    }

    /**
     * Send a paid transaction's active tickets to the account for an email.
     * If that account already holds every ticket nothing changes and the tickets are re-sent to it;
     * otherwise the tickets are transferred to it, creating a guest customer account when none exists.
     *
     * @return array{mode: string, recipient: User, ticket_count: int, transferred_count: int}
     * @throws UnauthorizedException
     */
    public function send(Transaction $transaction, string $email): array
    {
        if ($transaction->payment_status !== Transaction::PAYMENT_STATUS['PAID']) {
            throw new UnauthorizedException('Only paid transactions can be sent.', 422);
        }

        $tickets = $transaction->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
            ->whereNull('used_at')
            ->with('event')
            ->get();

        if ($tickets->isEmpty()) {
            throw new UnauthorizedException('This transaction has no active tickets left to send.', 422);
        }

        $recipient = DB::transaction(function () use ($transaction, $tickets, $email) {
            $recipient = GuestCustomerService::resolveByEmail($email);

            if ($recipient->status !== GeneralConstants::GENERAL_STATUSES['ACTIVE']) {
                throw new UnauthorizedException('The account for this email is not active.', 422);
            }

            foreach ($tickets as $ticket) {
                if ($ticket->user_uuid !== $recipient->uuid) {
                    $this->ticketRepository->transferTicket($ticket, $recipient);
                }
            }

            // The buyer keeps the order, so the group QR would still admit tickets that now belong to someone else.
            if ($tickets->contains(fn (Ticket $ticket) => $ticket->user_uuid !== $recipient->uuid)) {
                $transaction->update(['qr_code' => null]);
            }

            return $recipient;
        });

        $transferred = $tickets->reject(fn (Ticket $ticket) => $ticket->user_uuid === $recipient->uuid);

        $this->notify($transaction, $recipient, $tickets->count(), $transferred);

        return [
            'mode' => $transferred->isEmpty() ? self::MODE_RESENT : self::MODE_TRANSFERRED,
            'recipient' => $recipient,
            'ticket_count' => $tickets->count(),
            'transferred_count' => $transferred->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Ticket>  $transferred
     */
    private function notify(Transaction $transaction, User $recipient, int $ticketCount, $transferred): void
    {
        $eventName = $transaction->event?->event_name ?? 'your event';
        $order = "order #{$transaction->order_number}";
        $data = ['transaction_uuid' => $transaction->uuid];

        if ($transferred->isEmpty()) {
            $this->notificationService->send(
                $recipient,
                'ticket_resent',
                'Your tickets are ready',
                'Your ' . self::ticketLabel($ticketCount, 'is', 'are') . " in your account for \"{$eventName}\" ({$order}).",
                '/account/tickets',
                $data,
            );

            return;
        }

        $this->notificationService->send(
            $recipient,
            'ticket_transfer_received',
            'Tickets sent to you',
            self::ticketLabel($transferred->count(), 'was', 'were') . " sent to your account for \"{$eventName}\" ({$order}).",
            '/account/tickets',
            $data,
        );

        User::query()
            ->whereIn('uuid', $transferred->pluck('user_uuid')->unique()->all())
            ->get()
            ->each(fn (User $holder) => $this->notificationService->send(
                $holder,
                'ticket_transfer_sent',
                'Tickets transferred',
                'Your ' . self::ticketLabel($transferred->where('user_uuid', $holder->uuid)->count(), 'was', 'were')
                    . " transferred to {$recipient->email} for \"{$eventName}\" ({$order}).",
                '/account/tickets',
                $data,
            ));
    }

    private static function ticketLabel(int $count, string $singularVerb, string $pluralVerb): string
    {
        return $count . ' ' . Str::plural('ticket', $count) . ' ' . ($count === 1 ? $singularVerb : $pluralVerb);
    }
}
