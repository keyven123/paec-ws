<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoTicketFoundException;
use App\Exceptions\NoUserFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Ticket;
use App\Helpers\GeneralHelper;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Event;
use Illuminate\Contracts\Database\Eloquent\Builder;
use App\Models\VenueSeat;
use App\Models\EventTicket;
use App\Jobs\RecordAffiliateCommissionReversalForCancelledTicketJob;
use App\Services\EventLocationService;
use App\Services\Platform\TransactionCommissionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketRepository
{
    /**
     * @param Ticket $ticket
     * @param User $user
     */
    public function __construct(
        protected Ticket $ticket,
        protected User $user,
        protected Transaction $transaction,
        protected Event $event,
        protected VenueSeat $venueSeat,
        protected EventTicket $eventTicket,
    ) {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->ticket->with(['user', 'transaction', 'event', 'eventTicket', 'transaction.schedule', 'transaction.scheduleTime'])
            ->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    public function getSpecificTicketByUser(array $filters): Builder
    {
        return $this->ticket->with([
            'transaction',
            'transaction.transactionOrders',
            'transaction.event',
            'transaction.tickets',
            'transaction.schedule',
            'transaction.scheduleTime',
            'event',
            'event.organization',
            'event.portraitImage',
            'event.featuredImage',
            'eventLocation',
            'eventTicket.scheduleTime',
            'venueSeat.venue',
            'transferredToUser'
        ])
            ->owner()
            ->filters($filters)
            ->notInactive()
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch ticket or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Ticket
     * @throws NoTicketFoundException
     */
    public function fetchOrThrow(string $key, string $value): Ticket
    {
        $ticket = $this->ticket->with(['user', 'transaction', 'event', 'eventTicket', 'eventLocation', 'ticketSeat'])
            ->where($key, $value)->first();

        if (is_null($ticket)) {
            throw new NoTicketFoundException();
        }

        return $ticket;
    }

    /**
     * Fetch ticket or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @param string $userUuid
     * @return Ticket
     * @throws NoTicketFoundException
     */
    public function fetchOrThrowByUser(string $key, string $value, string $userUuid): Ticket
    {
        return $this->ticket
            ->active()
            ->ownedBy($userUuid)
            ->where($key, $value)
            ->first();
    }

    /**
     * @param array $payload
     * @return Ticket
     */
    public function create(array $payload): Ticket
    {
        $ticketPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Ticket::DATA);
        return $this->ticket->create($ticketPayload);
    }

    /**
     * @param Ticket $ticket
     * @param array $payload
     * @return bool|Ticket
     */
    public function update(Ticket $ticket, array $payload): bool|Ticket
    {
        $ticketPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Ticket::DATA);
        return $ticket->update($ticketPayload);
    }

    /**
     * @param Ticket $ticket
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Ticket $ticket): void
    {
        // Check if ticket has been used
        if ($ticket->used_at) {
            throw new UnauthorizedException('Cannot delete a used ticket.');
        }

        // Check if ticket has associated ticket seats
        if ($ticket->ticketSeat()->exists()) {
            throw new UnauthorizedException('Cannot delete ticket with associated ticket seats.');
        }

        $ticket->delete();
    }

    /**
     * Mark ticket as used
     * @param Ticket $ticket
     * @return bool
     * @throws UnauthorizedException
     */
    public function markAsUsed(Ticket $ticket): bool
    {
        if ($ticket->used_at) {
            throw new UnauthorizedException('Ticket has already been used.');
        }

        if ($ticket->status !== 'active') {
            throw new UnauthorizedException('Only active tickets can be used.');
        }

        return $ticket->update([
            'used_at' => now(),
            'status' => 'consumed'
        ]);
    }

    /**
     * Mark ticket as used (for customer virtual events)
     * @param Ticket $ticket
     * @return bool
     * @throws UnauthorizedException
     */
    public function markAsUsedForCustomer(Ticket $ticket): bool
    {
        if ($ticket->used_at) {
            throw new UnauthorizedException('Ticket has already been used.');
        }

        if ($ticket->status !== GeneralConstants::TICKET_STATUSES['ACTIVE']) {
            throw new UnauthorizedException('Only active tickets can be used.');
        }

        return $ticket->update([
            'used_at' => now(),
            'status' => GeneralConstants::TICKET_STATUSES['USED']
        ]);
    }

    /**
     * Transfer ticket to another user
     * @param Ticket $ticket
     * @param string $newUserUuid
     * @return bool
     * @throws UnauthorizedException
     */
    public function transferTicket(Ticket $ticket, User $user): bool
    {
        if ($ticket->used_at) {
            throw new UnauthorizedException('Cannot transfer a used ticket.');
        }

        if ($ticket->status !== 'active') {
            throw new UnauthorizedException('Only active tickets can be transferred.');
        }

        $newTicket = $this->ticket->create([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $ticket->transaction_uuid,
            'organization_uuid' => $ticket->organization_uuid,
            'event_uuid' => $ticket->event_uuid,
            'event_ticket_uuid' => $ticket->event_ticket_uuid,
            'venue_seat_uuid' => $ticket->venue_seat_uuid,
            'col' => $ticket->col,
            'row' => $ticket->row,
            'attendee_name' => $user->full_name,
            'attendee_email' => $user->email,
            'attendee_contact' => $user->phone_number ?? null,
            'transfer_count' => $ticket->transfer_count + 1,
            'ticket_number' => GeneralHelper::generateUuidTicketNumber($ticket->event->ticket_prefix ?? 'TKT'),
            'qr_code' => GeneralHelper::generateQrCode($this->ticket, 'TRFR-'),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ]);

        $ticket->coupons()->update([
            'user_uuid' => $user->uuid,
            'ticket_uuid' => $newTicket->uuid,
        ]);

        return $ticket->update([
            'status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED'],
            'transferred_to' => $user->uuid,
            'transferred_at' => now(),
            'transfer_count' => $ticket->transfer_count + 1,
            'transferred_by' => request()->user()->uuid,
        ]);
    }

    public function transferTicketByEmail(Ticket $ticket, string $newUserEmail, string $currentUserUuid): bool
    {
        $newUser = $this->user->where('email', $newUserEmail)->first();

        if (is_null($newUser)) {
            throw new NoUserFoundException();
        }

        if ($newUser->status !== GeneralConstants::GENERAL_STATUSES['ACTIVE']) {
            throw new UnauthorizedException('The selected user is not active.');
        }

        if ($newUser->uuid === $currentUserUuid) {
            throw new UnauthorizedException('You cannot transfer a ticket to yourself.');
        }

        return $this->transferTicket($ticket, $newUser);
    }

    public function getRecentPurchasedTickets(array $filters): Builder
    {
        return $this->ticket
            ->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
            })
            ->filters($filters)
            ->latest()
            ->take($filters['per_page'] ?? 10);
    }

    /**
     * Get ticket by QR code
     * @param string $qrCode
     * @param string|null $eventUuid
     * @return Ticket
     * @throws NoTicketFoundException
     */
    public function getByQrCode(string $qrCode, ?string $eventUuid = null): Ticket
    {
        $query = $this->ticket->with([
            'user',
            'transaction',
            'event',
            'eventTicket',
            'eventLocation',
            'ticketSeat',
            'venueSeat',
            'transaction.schedule',
            'transaction.scheduleTime'
        ])
            ->where('qr_code', $qrCode);

        if ($eventUuid) {
            $query->where('event_uuid', $eventUuid);
        }

        $ticket = $query->first();

        if (is_null($ticket)) {
            throw new NoTicketFoundException();
        }

        $ticket->loadMissing([
            'transaction.transactionOrders',
            'eventTicket',
        ]);

        return $ticket;
    }

    /**
     * Confirm entry - mark ticket as used
     * @param Ticket $ticket
     * @return bool
     * @throws UnauthorizedException
     */
    public function confirmEntry(Ticket $ticket): bool
    {
        if ($ticket->used_at) {
            throw new UnauthorizedException('Ticket has already been used.');
        }

        if ($ticket->status !== GeneralConstants::TICKET_STATUSES['ACTIVE']) {
            throw new UnauthorizedException('Only active tickets can be confirmed for entry.');
        }

        $ticket->event->increment('registration_count');

        return $ticket->update([
            'used_at' => now(),
            'status' => GeneralConstants::TICKET_STATUSES['USED']
        ]);
    }

    public function addTicketToUser(array $payload): bool
    {
        DB::beginTransaction();
        try {
            $event = $this->event->where('uuid', $payload['event_uuid'])->first();
            if ($event && $event->event_config == Event::EVENT_CONFIGS['SEAT_SELECTION'] && $payload['quantity'] > 1) {
                throw new UnauthorizedException('Seat selection events can only add 1 ticket at a time.');
            }
            $eventLocation = EventLocationService::resolveForCheckout(
                $event,
                $payload['event_location_uuid'] ?? null,
            );
            $organizationUuid = EventLocationService::resolveOrganizationUuid($eventLocation, $event);
            $eventTicket = $this->eventTicket->where('uuid', $payload['event_ticket_uuid'])->first();
            $user = $this->user->where('uuid', $payload['user_uuid'])->first();

            $validUntil = null;
            if (!empty($payload['valid_until'])) {
                $validUntil = Carbon::parse($payload['valid_until'])->endOfDay();
            } elseif ($eventTicket && $eventTicket->visit_policy === 'flexible' && $eventTicket->validity_days) {
                $validUntil = now()->addDays((int) $eventTicket->validity_days)->endOfDay();
            }

            $quantity = (int) ($payload['quantity'] ?? 1);
            $isPaidType = in_array($payload['type'], [
                Ticket::TYPES['PAID'],
                Ticket::TYPES['PAID_NR'],
                Ticket::TYPES['PAID_TO_MERCHANT'],
            ], true);

            $usesExplicitTotalAmount = in_array($payload['type'], [
                Ticket::TYPES['PAID_NR'],
                Ticket::TYPES['PAID_TO_MERCHANT'],
            ], true);

            if ($usesExplicitTotalAmount && array_key_exists('amount', $payload)) {
                $lineTotal = round((float) $payload['amount'], 2);
                $unitAmount = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0;
            } else {
                $unitAmount = (float) $eventTicket->price;
                $lineTotal = $isPaidType ? round($unitAmount * $quantity, 2) : 0.0;
            }
            if (isset($payload['venue_seat_uuid']) && !is_null($payload['venue_seat_uuid'])) {
                $venueSeat = $this->venueSeat
                    ->where('uuid', $payload['venue_seat_uuid'])
                    ->whereStatus(GeneralConstants::GENERAL_STATUSES['ACTIVE'])
                    ->firstOrFail();
            }
            $paymentProvider = $payload['type'] === Ticket::TYPES['COMPLEMENTARY']
                ? null
                : 'manual';

            $transaction = $this->transaction->create([
                'user_uuid' => $payload['user_uuid'],
                'event_uuid' => $payload['event_uuid'],
                'event_location_uuid' => $eventLocation->uuid,
                'organization_uuid' => $organizationUuid,
                'order_number' => GeneralHelper::generateOrderNumber('GIFT-'),
                'total_amount' => $lineTotal,
                'sub_total' => $lineTotal,
                'payment_provider' => $paymentProvider,
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
                'paid_at' => now(),
                'created_by' => request()->user()->uuid,
                'updated_by' => request()->user()->uuid,
            ]);

            $transaction->transactionOrders()->create([
                'user_uuid' => $payload['user_uuid'],
                'event_ticket_uuid' => $payload['event_ticket_uuid'],
                'quantity' => $quantity,
                'price' => $unitAmount,
                'total_amount' => $lineTotal,
                'valid_until' => $validUntil,
            ]);

            $ticketQrPrefix = match (true) {
                $payload['type'] === Ticket::TYPES['COMPLEMENTARY'] => 'CMPL-',
                $payload['type'] === Ticket::TYPES['PAID_NR'] => 'PDNR-',
                $payload['type'] === Ticket::TYPES['PAID_TO_MERCHANT'] => 'PTME-',
                default => 'GIFT-',
            };

            $otherInfoPayload = $payload['other_info'] ?? [];
            $emptyOtherInfoTemplate = null;
            if ($event->other_info && is_array($event->other_info)) {
                $emptyOtherInfoTemplate = [];
                foreach (array_keys($event->other_info) as $fieldName) {
                    $emptyOtherInfoTemplate[$fieldName] = '';
                }
                if ($emptyOtherInfoTemplate === []) {
                    $emptyOtherInfoTemplate = null;
                }
            }

            for ($i = 0; $i < $quantity; $i++) {
                $ticketAttributes = [
                    'user_uuid' => $payload['user_uuid'],
                    'organization_uuid' => $organizationUuid,
                    'transaction_uuid' => $transaction->uuid,
                    'event_uuid' => $payload['event_uuid'],
                    'event_location_uuid' => $eventLocation->uuid,
                    'event_ticket_uuid' => $payload['event_ticket_uuid'],
                    'venue_seat_uuid' => $payload['venue_seat_uuid'] ?? null,
                    'col' => isset($venueSeat) && !is_null($venueSeat) ? $venueSeat->col : null,
                    'row' => isset($venueSeat) && !is_null($venueSeat) ? $venueSeat->row : null,
                    'attendee_name' => $user->full_name,
                    'attendee_email' => $user->email,
                    'attendee_contact' => $user->phone_number ?? null,
                    'qr_code' => GeneralHelper::generateQrCode($this->ticket, $ticketQrPrefix),
                    'ticket_number' => GeneralHelper::generateUuidTicketNumber($event->ticket_prefix ?? 'TKT'),
                    'type' => $payload['type'],
                    'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
                    'visit_policy' => $eventTicket->visit_policy,
                    'created_by' => request()->user()->uuid,
                    'updated_by' => request()->user()->uuid,
                ];

                if ($validUntil) {
                    $ticketAttributes['valid_until'] = $validUntil;
                }

                if (isset($otherInfoPayload[$i]) && is_array($otherInfoPayload[$i])) {
                    $ticketAttributes['other_info'] = $otherInfoPayload[$i];
                } elseif ($emptyOtherInfoTemplate !== null) {
                    $ticketAttributes['other_info'] = $emptyOtherInfoTemplate;
                }

                $ticket = $this->ticket->create($ticketAttributes);
                foreach ($eventTicket->coupons as $coupon) {
                    $ticket->coupons()->create([
                        'user_uuid' => $payload['user_uuid'],
                        'event_uuid' => $payload['event_uuid'],
                        'event_ticket_coupon_uuid' => $coupon->uuid,
                        'name' => $coupon->name,
                        'qr_code' => GeneralHelper::generateQrCode($this->ticket, 'COUPON_'),
                        'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE']
                    ]);
                }
            }
            DB::commit();

            // Record commission ledger row for paid gift / manual tickets.
            // Free/complementary tickets (total_amount = 0) are skipped inside the service.
            if ($lineTotal > 0) {
                app(TransactionCommissionService::class)->recordPaidTransaction(
                    $transaction->fresh()->load('event.organization')
                );
            }

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function downloadTicket(Ticket $ticket): bool
    {
        return $ticket->update([
            'is_downloaded' => true,
        ]);
    }

    public function exportUsedTickets(Event $event, ?Carbon $start = null, ?Carbon $end = null): string
    {
        $tickets = $event->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['USED'])
            ->when($start !== null && $end !== null, fn ($query) => $query->whereBetween('used_at', [$start, $end]))
            ->get();

        $additionalHeaders = [];
        $data = [];

        // Add CSV headers
        $headers = [
            'Ticket Number',
            'Ticket Type',
            'Customer Name',
            'Attendee Name',
            'Attendee Email',
            'Attendee Contact',
            'Seat Number',
            'Used At',
            'Status',
        ];

        if ($event->other_info && is_array($event->other_info)) {
            foreach ($event->other_info as $key => $field) {
                $additionalHeaders[] = $key;
            }
        }

        $headers = array_merge($headers, $additionalHeaders);

        $data[] = $headers;

        foreach ($tickets as $ticket) {
            $additionalFields = [];
            $fields = [
                $ticket->ticket_number,
                $ticket->eventTicket->name,
                $ticket->user->full_name,
                $ticket->attendee_name,
                $ticket->attendee_email,
                $ticket->attendee_contact,
                $ticket->col . '-' . $ticket->row,
                $ticket->used_at ? Carbon::parse($ticket->used_at)->format('Y/m/d H:i:s') : 'N/A',
                $ticket->status,
            ];

            if ($additionalHeaders) {
                if (is_array($ticket->other_info)) {
                    foreach ($ticket->other_info as $field) {
                        $additionalFields[] = $field ?? '';
                    }
                } else {
                    $additionalFields = array_fill(0, count($additionalHeaders), '');
                }
            }

            $fields = array_merge($fields, $additionalFields);

            $data[] = $fields;
        }

        // Generate CSV content
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    public function exportOccupiedSeats(Event $event, ?string $scheduleUuid = null, ?string $scheduleTimeUuid = null): string
    {
        $query = VenueSeat::where('venue_uuid', $event->venue_uuid)
            ->leftJoin('tickets', 'venue_seats.uuid', '=', 'tickets.venue_seat_uuid')
            ->leftJoin('transactions', 'tickets.transaction_uuid', '=', 'transactions.uuid')
            ->select('venue_seats.*', 'tickets.attendee_name', 'transactions.order_number')
            ->where('transactions.payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('venue_seats.status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->where('tickets.event_uuid', $event->uuid)
            ->whereNotIn('tickets.status', [
                GeneralConstants::TICKET_STATUSES['TRANSFERRED'],
                GeneralConstants::TICKET_STATUSES['CANCELLED']
            ]);

        if ($scheduleUuid) {
            $query->where('transactions.schedule_uuid', $scheduleUuid);
        }

        if ($scheduleTimeUuid) {
            $query->where('transactions.schedule_time_uuid', $scheduleTimeUuid);
        }

        $seats = $query->orderBy('row', 'asc')
            ->orderBy('col', 'asc')
            ->orderBy('seat_no', 'asc')
            ->get();

        $data = [];

        $headers = [
            'Event Name',
            'Venue',
            'Row',
            'Column',
            'Seat No',
            'Attendee Name',
            'Status'
        ];
        $data[] = $headers;

        foreach ($seats as $seat) {
            $data[] = [
                $event->event_name,
                $event->venue->name,
                $seat->col,
                $seat->row,
                $seat->seat_no,
                $seat->attendee_name ?? '',
                $seat->attendee_name ? 'Occupied' : 'Available',
            ];
        }

        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    public function exportTickets(Event $event, ?Carbon $start = null, ?Carbon $end = null): string
    {
        $tickets = $event->tickets()
            ->with(['event', 'transferredToUser', 'transferredByUser'])
            ->when($start !== null && $end !== null, fn ($query) => $query->whereBetween('created_at', [$start, $end]))
            ->orderBy('created_at', 'asc')
            ->get();

        $data = [];

        // Add CSV headers
        $headers = [
            '#',
            'Event Name',
            'Ticket Number',
            'QR Code',
            'Seat Number',
            'Status',
            'Attendee Name',
            'Attendee Email',
            'Date Used',
            'Date Transfer',
            'Transfer to',
            'Transfer to email',
            'Transferred By',
            'Type',
            'Remarks',
        ];

        $data[] = $headers;

        foreach ($tickets as $index => $ticket) {
            $seatNumber = $ticket->col && $ticket->row
                ? $ticket->col . '-' . $ticket->row
                : '';

            $data[] = [
                $index + 1,
                $ticket->event?->event_name ?? $event->event_name ?? '',
                $ticket->ticket_number,
                $ticket->qr_code,
                $seatNumber,
                $ticket->status,
                $ticket->attendee_name,
                $ticket->attendee_email,
                $ticket->used_at ? Carbon::parse($ticket->used_at)->format('Y/m/d H:i:s') : 'N/A',
                $ticket->transferred_at ? Carbon::parse($ticket->transferred_at)->format('Y/m/d H:i:s') : 'N/A',
                $ticket->transferredToUser?->full_name ?? '',
                $ticket->transferredToUser?->email ?? '',
                $ticket->transfer_count > 0
                    ? ($ticket->transferredByUser?->full_name ?? $ticket->user?->full_name ?? '')
                    : '',
                $ticket->type ?? '',
                $ticket->remarks ?? '',
            ];
        }

        // Generate CSV content
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    /**
     * Net cash attributed to this ticket's line on the transaction (promo / order-level split).
     *
     * @throws \RuntimeException When the ticket has no matching transaction order line or totals are invalid
     */
    private function netAmountPaidForTicket(Ticket $ticket, Transaction $transaction): float
    {
        $transactionOrder = $transaction->transactionOrders()->where('event_ticket_uuid', $ticket->event_ticket_uuid)->first();
        if (!$transactionOrder) {
            throw new \RuntimeException('Transaction order not found for this ticket.');
        }
        $qty = max(1, (int) $transactionOrder->quantity);
        $sumLineTotals = (float) $transaction->transactionOrders()->sum('total_amount');
        if ($sumLineTotals <= 0) {
            throw new \RuntimeException('Invalid transaction order totals.');
        }

        return ((float) $transactionOrder->total_amount * (float) $transaction->total_amount / $sumLineTotals) / $qty;
    }

    /**
     * Retire the current ticket and issue a new active ticket for the target type.
     * Records a single upgrade transaction whose {@see Transaction::total_amount} is the **incremental**
     * cash for this step: max(0, {@see $amount} − net amount already paid for this ticket on the original order).
     * sub_total is the destination tier list price; line discount is max(0, list − incremental).
     *
     * @param Ticket $ticket Active issued ticket to upgrade from
     * @param EventTicket $targetEventTicket Destination ticket type (same event)
     * @param float $amount Declared total for the upgraded tier (UI input); persisted total is incremental only
     * @return Ticket The newly created upgraded ticket
     */
    public function upgrade(Ticket $ticket, EventTicket $targetEventTicket, float $amount): Ticket
    {
        if ($ticket->used_at) {
            throw new UnauthorizedException('Cannot upgrade a used ticket.');
        }

        if ($ticket->status !== GeneralConstants::TICKET_STATUSES['ACTIVE']) {
            throw new UnauthorizedException('Only active tickets can be upgraded.');
        }

        if ($targetEventTicket->event_uuid !== $ticket->event_uuid) {
            throw new UnauthorizedException('Upgrade target must belong to the same event.');
        }

        if ($targetEventTicket->uuid === $ticket->event_ticket_uuid) {
            throw new UnauthorizedException('Choose a different ticket type to upgrade to.');
        }

        if ($targetEventTicket->status !== GeneralConstants::GENERAL_STATUSES['ACTIVE']) {
            throw new UnauthorizedException('The selected ticket type is not active.');
        }

        if ($targetEventTicket->is_bundle) {
            throw new UnauthorizedException('Bundle ticket types cannot be used for upgrades.');
        }

        if (
            !$targetEventTicket->is_unlimited
            && (int) $targetEventTicket->max_ticket > 0
            && (int) $targetEventTicket->sold_ticket >= (int) $targetEventTicket->max_ticket
        ) {
            throw new UnauthorizedException('The selected ticket type has no remaining inventory.');
        }

        $ticket->loadMissing(['transaction', 'eventTicket', 'event', 'venueSeat', 'ticketSeat']);

        $transaction = $ticket->transaction;
        if (!$transaction) {
            throw new UnauthorizedException('Ticket has no transaction.');
        }

        DB::beginTransaction();
        try {
            $newPrice = (float) $targetEventTicket->price;
            $priorPaidForThisTicket = $this->netAmountPaidForTicket($ticket, $transaction);
            $incrementalAmount = max(0, $amount - $priorPaidForThisTicket);
            $lineDiscount = max(0, $newPrice - $incrementalAmount);

            $ticket->coupons()->update([
                'status' => GeneralConstants::TICKET_COUPON_STATUSES['CANCELLED'],
            ]);

            if ($ticket->ticketSeat()->exists()) {
                $ticket->ticketSeat->delete();
            }

            $ticket->eventTicket->decrement('sold_ticket', 1);
            $ticket->event->decrement('ticket_sold', 1);

            $remarks = 'Upgraded to: ' . $targetEventTicket->name;
            $ticket->update([
                'status' => GeneralConstants::TICKET_STATUSES['CANCELLED'],
                'remarks' => $remarks,
                'updated_by' => request()->user()->uuid,
            ]);

            $upgradeTransaction = Transaction::create([
                'user_uuid' => $ticket->user_uuid,
                'event_uuid' => $ticket->event_uuid,
                'schedule_uuid' => $transaction->schedule_uuid,
                'schedule_time_uuid' => $transaction->schedule_time_uuid,
                'organization_uuid' => $ticket->organization_uuid,
                'affiliate_partner_uuid' => null,
                'payment_provider' => 'upgrade',
                'order_number' => GeneralHelper::generateOrderNumber('UPGR-'),
                'sub_total' => $newPrice,
                'discount' => $lineDiscount,
                'total_amount' => $incrementalAmount,
                'status' => Transaction::STATUS['ACTIVE'],
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
                'paid_at' => now(),
                'created_by' => request()->user()->uuid,
                'updated_by' => request()->user()->uuid,
            ]);

            $upgradeTransaction->transactionOrders()->create([
                'user_uuid' => $ticket->user_uuid,
                'event_ticket_uuid' => $targetEventTicket->uuid,
                'quantity' => 1,
                'price' => $newPrice,
                'discount' => $lineDiscount,
                'total_amount' => $incrementalAmount,
                'seats' => null,
            ]);

            $event = $ticket->event;
            $newTicket = $this->ticket->create([
                'user_uuid' => $ticket->user_uuid,
                'organization_uuid' => $ticket->organization_uuid,
                'transaction_uuid' => $upgradeTransaction->uuid,
                'event_uuid' => $ticket->event_uuid,
                'event_ticket_uuid' => $targetEventTicket->uuid,
                'venue_seat_uuid' => null,
                'col' => null,
                'row' => null,
                'attendee_name' => $ticket->attendee_name,
                'attendee_email' => $ticket->attendee_email,
                'attendee_contact' => $ticket->attendee_contact,
                'ticket_number' => GeneralHelper::generateUuidTicketNumber($event->ticket_prefix ?? 'TKT'),
                'qr_code' => GeneralHelper::generateQrCode($this->ticket, 'UPGR-'),
                'type' => Ticket::TYPES['UPGRADE'],
                'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
                'created_by' => request()->user()->uuid,
                'updated_by' => request()->user()->uuid,
            ]);

            foreach ($targetEventTicket->coupons as $coupon) {
                $newTicket->coupons()->create([
                    'user_uuid' => $ticket->user_uuid,
                    'event_uuid' => $ticket->event_uuid,
                    'event_ticket_coupon_uuid' => $coupon->uuid,
                    'name' => $coupon->name,
                    'qr_code' => GeneralHelper::generateQrCode($this->ticket, 'COUPON_'),
                    'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
                ]);
            }

            $targetEventTicket->increment('sold_ticket', 1);
            $event->increment('ticket_sold', 1);

            DB::commit();

            // Records gross/net + ticketoc commission on the *incremental* amount
            // only. Gateway fee = 0 (no online payment for upgrades) and agent
            // commission = 0 (upgrades carry no affiliate attribution).
            (new TransactionCommissionService())->recordPaidTransaction(
                $upgradeTransaction->fresh()->load('event.organization')
            );

            return $newTicket;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function cancel(Ticket $ticket, string $remarks): bool
    {
        if ($ticket->status !== GeneralConstants::TICKET_STATUSES['ACTIVE']) {
            throw new UnauthorizedException('Only active tickets can be cancelled.');
        }
        DB::beginTransaction();
        try {
            $transaction = $ticket->transaction;
            $transactionOrder = $transaction->transactionOrders()->where('event_ticket_uuid', $ticket->event_ticket_uuid)->first();
            if (!$transactionOrder) {
                throw new \RuntimeException('Transaction order not found for this ticket.');
            }
            // Net paid for this line after promo (and any other order-level adjustments) is proportional to line total.
            $perTicketPaid = $this->netAmountPaidForTicket($ticket, $transaction);
            $unitList = (float) $transactionOrder->price;
            $perTicketDiscount = max(0, $unitList - $perTicketPaid);
            $seat = $ticket->venueSeat;
            $refundTransaction = Transaction::create([
                'user_uuid' => $transaction->user_uuid,
                'event_uuid' => $transaction->event_uuid,
                'schedule_uuid' => $transaction->schedule_uuid,
                'schedule_time_uuid' => $transaction->schedule_time_uuid,
                'organization_uuid' => $transaction->organization_uuid,
                'affiliate_partner_uuid' => $transaction->affiliate_partner_uuid,
                'payment_order_id' => $transaction->payment_order_id,
                'payment_provider' => 'refund',
                'order_number' => GeneralHelper::generateOrderNumber('CANCEL-'),
                'sub_total' => $unitList,
                'discount' => $perTicketDiscount,
                'total_amount' => -(float) $perTicketPaid,
                'status' => Transaction::STATUS['REFUNDED'],
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            ]);
            $refundTransaction->transactionOrders()->create([
                'user_uuid' => $transactionOrder->user_uuid,
                'event_ticket_uuid' => $transactionOrder->event_ticket_uuid,
                'quantity' => 1,
                'price' => (float) $transactionOrder->price,
                'total_amount' => $perTicketPaid,
                'seats' => $seat ? [
                    'uuid' => $seat->uuid,
                    'row' => $seat->row,
                    'col' => $seat->col,
                    'seat_no' => $seat->seat_no,
                ] : null,
                'discount' => $refundTransaction->discount
            ]);

            app(TransactionCommissionService::class)->recordRefundedTransaction(
                $refundTransaction,
                $transaction->paid_at?->toIso8601String(),
            );

            $ticket->coupons()->update([
                'status' => GeneralConstants::TICKET_COUPON_STATUSES['CANCELLED'],
            ]);

            if ($ticket->ticketSeat()->exists()) {
                $ticket->ticketSeat->delete();
            }

            $ticket->eventTicket->decrement('sold_ticket', 1);
            $ticket->event->decrement('ticket_sold', 1);

            $updated = $ticket->update([
                'status' => GeneralConstants::TICKET_STATUSES['CANCELLED'],
                'remarks' => $remarks,
                'updated_by' => request()->user()->uuid,
            ]);

            DB::commit();

            if ($updated) {
                dispatch(new RecordAffiliateCommissionReversalForCancelledTicketJob($ticket->uuid));
            }

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
